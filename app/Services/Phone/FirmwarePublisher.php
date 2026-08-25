<?php

namespace App\Services\Phone;

use App\Models\DeviceModel;
use App\Models\PhoneFirmware;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Turns whatever an admin hands us (a bare .bin, or the ZIP straight off
 * Grandstream's download page) into firmware images the phones can fetch, and
 * publishes the chosen image to the flat directory nginx serves.
 *
 * Two rules drive everything here:
 *
 *  1. **Never rename a vendor .bin.** The phone requests one literal filename
 *     (grp2601fw.bin) from its configured firmware path. Rename it and the phone
 *     404s forever with no visible error.
 *  2. **The serve root is flat.** Every Grandstream model asks for a distinct
 *     filename, so one directory serves the whole fleet — no per-model folders.
 */
class FirmwarePublisher
{
    /** Refuse absurd archives outright rather than filling the disk. */
    private const MAX_EXTRACTED_BYTES = 1024 * 1024 * 1024; // 1 GB

    private const MAX_ENTRIES = 500;

    /**
     * Pull the firmware image(s) out of an uploaded/fetched file. Each returned
     * `path` is a temp file the caller owns and must clean up.
     *
     * @return array<int, array{filename: string, path: string, size: int}>
     */
    public function extractImages(string $localPath, string $originalName): array
    {
        if (! is_file($localPath)) {
            throw new RuntimeException('Uploaded file could not be read.');
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext === 'bin') {
            return [[
                'filename' => $this->safeBinName($originalName),
                'path' => $localPath,
                'size' => (int) filesize($localPath),
            ]];
        }

        if ($ext !== 'zip') {
            throw new RuntimeException('Expected a .bin firmware image or the vendor .zip.');
        }

        return $this->extractFromZip($localPath);
    }

    /**
     * @return array<int, array{filename: string, path: string, size: int}>
     */
    private function extractFromZip(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open the ZIP archive.');
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $count = $zip->numFiles;
            $zip->close();
            throw new RuntimeException("Archive has {$count} entries — that is not a firmware package.");
        }

        $dir = $this->makeTempDir();
        $images = [];
        $totalBytes = 0;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $entry = $stat['name'];

                // Directory entries carry no payload.
                if (str_ends_with($entry, '/')) {
                    continue;
                }

                // Only the firmware images matter — vendor ZIPs also carry release
                // notes, PDFs and checksum files we have no use for.
                $base = basename(str_replace('\\', '/', $entry));
                if (strtolower(pathinfo($base, PATHINFO_EXTENSION)) !== 'bin') {
                    continue;
                }

                // Traversal guard. We only ever write basename() into our own temp
                // dir, so a crafted entry cannot escape — but reject it loudly
                // anyway rather than silently accepting a hostile archive.
                if ($this->isUnsafeEntry($entry)) {
                    throw new RuntimeException("Refusing archive: unsafe entry path \"{$entry}\".");
                }

                $totalBytes += (int) $stat['size'];
                if ($totalBytes > self::MAX_EXTRACTED_BYTES) {
                    throw new RuntimeException('Archive expands past the 1 GB limit.');
                }

                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    throw new RuntimeException("Could not read \"{$entry}\" from the archive.");
                }

                $target = $dir.'/'.$this->safeBinName($base);
                $out = fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new RuntimeException('Could not write the extracted image.');
                }
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);

                $images[] = [
                    'filename' => basename($target),
                    'path' => $target,
                    'size' => (int) filesize($target),
                ];
            }
        } finally {
            $zip->close();
        }

        if ($images === []) {
            throw new RuntimeException('No .bin firmware image found inside the archive.');
        }

        return $images;
    }

    /**
     * An entry is unsafe if it is absolute, walks up with .., or carries a
     * Windows drive letter. Checked against both separators — ZIP entries are
     * supposed to use "/" but nothing enforces it.
     */
    public function isUnsafeEntry(string $entry): bool
    {
        $normalised = str_replace('\\', '/', $entry);

        if ($normalised === '' || str_starts_with($normalised, '/')) {
            return true;
        }
        if (preg_match('/^[A-Za-z]:/', $normalised)) {
            return true;
        }
        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep the vendor's name but strip anything that is not a plain filename.
     * Grandstream images are already lowercase alnum (grp2601fw.bin); this only
     * fires on something malformed.
     */
    public function safeBinName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^A-Za-z0-9._-]/', '', $base) ?: '';

        if ($base === '' || ! str_ends_with(strtolower($base), '.bin')) {
            throw new RuntimeException("\"{$name}\" is not a usable firmware filename.");
        }

        return $base;
    }

    /**
     * Best-effort model guess from the vendor filename: grp2601fw.bin → GRP2601.
     * Always shown to the admin for confirmation — never trusted blindly.
     */
    public function guessModel(string $filename): ?string
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);          // grp2601fw
        $stem = preg_replace('/fw$/i', '', $stem) ?: $stem;      // grp2601

        return $stem !== '' ? strtoupper($stem) : null;
    }

    /**
     * Best-effort version guess. Grandstream puts it in the ZIP name or the
     * folder inside it (…_1.0.13.59.zip), never in a place we can rely on, so
     * this is a convenience only.
     */
    public function guessVersion(string ...$haystacks): ?string
    {
        foreach ($haystacks as $haystack) {
            if (preg_match('/(\d+\.\d+\.\d+(?:\.\d+)?)/', $haystack, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Publish an image: copy it to the flat serve root under its vendor
     * filename, retire any other image that served the same models, and record
     * the version on the matching device_models rows so the existing Firmware
     * Tracker (/admin/devices/firmware) reflects it.
     */
    public function publish(PhoneFirmware $firmware): void
    {
        if (! $firmware->isStored() || ! $firmware->path) {
            throw new RuntimeException('That image has not finished storing yet.');
        }

        $disk = Storage::disk(PhoneFirmware::DISK);

        if (! $disk->exists($firmware->path)) {
            throw new RuntimeException('The firmware file is missing from storage.');
        }

        // Same filename == same model family, so an older image at the root is
        // simply overwritten. A different filename (a model split) is retired below.
        $stream = $disk->readStream($firmware->path);
        if ($stream === false) {
            throw new RuntimeException('Could not read the stored firmware image.');
        }
        try {
            $disk->writeStream($firmware->filename, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        PhoneFirmware::query()
            ->where('id', '!=', $firmware->id)
            ->where('is_active', true)
            ->get()
            ->each(function (PhoneFirmware $other) use ($firmware, $disk) {
                if (! $this->overlaps($other, $firmware)) {
                    return;
                }
                // Only pull the served file when the replacement did not already
                // overwrite it under the same name.
                if ($other->filename !== $firmware->filename) {
                    $disk->delete($other->filename);
                }
                $other->update(['is_active' => false]);
            });

        $firmware->update([
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->stampDeviceModels($firmware);
    }

    /** Stop serving an image. The library copy stays. */
    public function unpublish(PhoneFirmware $firmware): void
    {
        Storage::disk(PhoneFirmware::DISK)->delete($firmware->filename);
        $firmware->update(['is_active' => false, 'published_at' => null]);
    }

    /** Remove an image entirely — library copy, served copy if it is the live one. */
    public function forget(PhoneFirmware $firmware): void
    {
        $disk = Storage::disk(PhoneFirmware::DISK);

        if ($firmware->is_active) {
            $disk->delete($firmware->filename);
        }
        if ($firmware->path) {
            $disk->delete($firmware->path);
            $disk->deleteDirectory(PhoneFirmware::LIBRARY_DIR.'/'.$firmware->id);
        }

        $firmware->delete();
    }

    /** Do two images claim any of the same phone models? */
    private function overlaps(PhoneFirmware $a, PhoneFirmware $b): bool
    {
        foreach ($a->coveredModels() as $token) {
            if ($b->coversModel(rtrim($token, '*'))) {
                return true;
            }
        }

        foreach ($b->coveredModels() as $token) {
            if ($a->coversModel(rtrim($token, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirror the published version onto device_models.latest_firmware so the
     * existing ITAM Firmware Tracker starts flagging phones that are behind,
     * with no second comparison engine to maintain.
     */
    private function stampDeviceModels(PhoneFirmware $firmware): void
    {
        foreach (DeviceModel::all() as $deviceModel) {
            if ($firmware->coversModel($deviceModel->name)) {
                $deviceModel->update(['latest_firmware' => $firmware->version]);
            }
        }
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/fwx_'.bin2hex(random_bytes(6));
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create a temporary extraction directory.');
        }

        return $dir;
    }
}
