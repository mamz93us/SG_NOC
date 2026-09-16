<?php

namespace App\Services\Archive;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Multi-page TIFF to PDF, with a small cache.
 *
 * About 35 GB of the archive is TIFF, most of it multi-page G4 faxes and
 * scanned service reports, and no browser will display one. tiff2pdf
 * (libtiff-tools) rewraps the pages into a PDF without re-encoding them, so the
 * conversion is fast and lossless — the G4 data is copied into the PDF as-is.
 *
 * The result is cached because the alternative is converting the same 3-page
 * scan every time somebody clicks it. The cache is disposable by definition:
 * deleting it costs one reconversion and never data, which is why it lives on
 * local disk rather than in Azure.
 *
 * Directory permissions are deliberate. PHP-FPM runs as www-data and the
 * scheduler as azureuser, and Flysystem creates private directories 0700 — a
 * file written by one that the other cannot read is a known way to break things
 * in this app (the first PDF knowledge import failed exactly that way). 0711 on
 * the directory and 0644 on the files keeps both able to read.
 */
class TiffConverter
{
    /** Refuse anything absurd rather than handing a 2 GB file to a subprocess. */
    private const MAX_SOURCE_BYTES = 512 * 1024 * 1024;

    private const TIMEOUT_SECONDS = 120;

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * Convert, or return the cached result.
     *
     * Null when the tool is missing or the conversion fails — the caller shows
     * "this cannot be displayed, download it" rather than an empty frame.
     */
    public function toPdf(string $sourcePath, string $cacheKey): ?string
    {
        $target = $this->cacheDirectory().'/'.$cacheKey.'.pdf';

        if (@is_file($target) && @filesize($target) > 0) {
            @touch($target); // keeps the least-recently-used pruning honest

            return $target;
        }

        if (! @is_file($sourcePath)) {
            return null;
        }

        if (@filesize($sourcePath) > self::MAX_SOURCE_BYTES) {
            Log::warning('[archive] refusing to convert an oversized TIFF: '.$sourcePath);

            return null;
        }

        $binary = $this->binary();

        if ($binary === null) {
            return null;
        }

        // Convert to a temporary name and move it into place, so a crash or a
        // timeout never leaves a half-written PDF looking like a cache hit.
        $temporary = $target.'.'.getmypid().'.part';

        $process = new Process([$binary, '-o', $temporary, $sourcePath]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if (! $process->isSuccessful() || ! @is_file($temporary) || @filesize($temporary) === 0) {
            @unlink($temporary);
            Log::warning('[archive] tiff2pdf failed for '.$sourcePath.': '.trim($process->getErrorOutput()));

            return null;
        }

        @rename($temporary, $target);
        @chmod($target, 0644);

        return @is_file($target) ? $target : null;
    }

    /**
     * The cache directory, created on first use.
     *
     * 0770, not 0711. Two users share this directory — the viewer (www-data)
     * writes conversions into it and archive:prune-cache (azureuser) lists and
     * deletes them — and 0711 gives a non-owner traverse only. So the prune
     * could neither glob() the directory nor unlink anything in it: it reported
     * "0 file(s) removed" every night while the cache grew without limit, which
     * is indistinguishable from a cache under its cap.
     *
     * The mode is only half of it. Whoever creates the directory owns it, so it
     * also needs a GROUP both users are in — owner azureuser, group www-data is
     * what NOC2 uses. prune() says so rather than returning a silent zero when
     * it cannot read the directory.
     */
    public function cacheDirectory(): string
    {
        $path = storage_path('app/private/'.trim((string) config('archive_portal.cache_path', 'archive-cache'), '/'));

        if (! @is_dir($path)) {
            @mkdir($path, 0770, true);
            @chmod($path, 0770);
        }

        return $path;
    }

    /**
     * Drop the least recently used files until the cache is under its cap.
     *
     * Called by archive:prune-cache rather than on a request: a page that
     * sometimes stops to delete a gigabyte is a page that sometimes times out.
     *
     * @return array{removed:int, freed_bytes:int, remaining_bytes:int, problem?:string}
     */
    public function prune(?float $maxGigabytes = null): array
    {
        $cap = (int) (($maxGigabytes ?? (float) config('archive_portal.cache_max_gb', 5)) * 1024 * 1024 * 1024);
        $directory = $this->cacheDirectory();

        // An unreadable directory globs to nothing, which reads exactly like an
        // empty cache. Report it instead: this is how the prune ran clean every
        // night for as long as the directory belonged to the other user.
        if (! is_readable($directory) || ! is_writable($directory)) {
            return [
                'removed' => 0,
                'freed_bytes' => 0,
                'remaining_bytes' => 0,
                'problem' => $directory.' cannot be listed and emptied by this user — it needs to be owned by the scheduler\'s user with the web user\'s group, mode 0770.',
            ];
        }

        $files = glob($directory.'/*') ?: [];

        $entries = [];
        $total = 0;

        foreach ($files as $file) {
            if (! @is_file($file)) {
                continue;
            }

            $size = (int) @filesize($file);
            $total += $size;
            $entries[] = ['path' => $file, 'size' => $size, 'atime' => (int) @fileatime($file) ?: (int) @filemtime($file)];
        }

        if ($total <= $cap) {
            return ['removed' => 0, 'freed_bytes' => 0, 'remaining_bytes' => $total];
        }

        usort($entries, fn (array $a, array $b) => $a['atime'] <=> $b['atime']);

        $removed = 0;
        $freed = 0;

        foreach ($entries as $entry) {
            if ($total - $freed <= $cap) {
                break;
            }

            if (@unlink($entry['path'])) {
                $removed++;
                $freed += $entry['size'];
            }
        }

        return ['removed' => $removed, 'freed_bytes' => $freed, 'remaining_bytes' => $total - $freed];
    }

    /**
     * tiff2pdf, if this host has it.
     *
     * Resolved rather than assumed: it comes from libtiff-tools, which is one
     * of the apt packages the archive deployment adds, and a portal that is
     * otherwise working should say "the converter is not installed" rather than
     * fail opaquely.
     */
    private function binary(): ?string
    {
        static $resolved = false;
        static $path = null;

        if ($resolved) {
            return $path;
        }

        $resolved = true;

        foreach (['/usr/bin/tiff2pdf', '/usr/local/bin/tiff2pdf'] as $candidate) {
            if (@is_executable($candidate)) {
                return $path = $candidate;
            }
        }

        $which = new Process(['which', 'tiff2pdf']);
        $which->setTimeout(5);
        $which->run();

        $found = trim($which->getOutput());

        return $path = ($which->isSuccessful() && $found !== '') ? $found : null;
    }
}
