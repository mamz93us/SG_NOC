<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\PhoneFirmware;
use App\Models\PhoneRequestLog;
use App\Services\Phone\FirmwarePublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The NOC's Grandstream firmware server.
 *
 * Admins upload (or point the NOC at) a vendor firmware package; the active image
 * per model family is published into the flat directory nginx serves, and phones
 * pick it up from the firmware path set once in the UCM Zero Config global policy.
 *
 * Bytes are deliberately served by nginx, not through here: a Laravel route would
 * hold a PHP-FPM worker for every 60 MB transfer, and `internal.ip` matches exact
 * IPs only, so it could not gate phone subnets anyway. This controller manages the
 * library and reports state; it never serves firmware to a phone.
 */
class PhoneFirmwareController extends Controller
{
    /**
     * Direct-upload ceiling in KB (512 MB). Note nginx `client_max_body_size` and
     * PHP's upload_max_filesize/post_max_size will reject a big upload long before
     * this validation rule sees it — see PHONE_FIRMWARE_SERVER.md. The URL fetch
     * below sidesteps both and is the better path for a full vendor package.
     */
    private const MAX_UPLOAD_KB = 524_288;

    public function __construct(private FirmwarePublisher $publisher) {}

    // ─────────────────────────────────────────────────────────────
    // Library
    // ─────────────────────────────────────────────────────────────

    public function index()
    {
        $this->authorize('view-phone-firmware');

        $firmwares = PhoneFirmware::with('uploader')
            ->orderBy('model')
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        return view('admin.phones.firmware.index', [
            'firmwares' => $firmwares,
            'serveRoot' => rtrim(Storage::disk(PhoneFirmware::DISK)->url(''), '/'),
            'internalPath' => config('phone_firmware.internal_url'),
            'publicPath' => config('phone_firmware.public_url'),
            // What PHP will actually accept. nginx has its own, lower-by-default
            // ceiling that rejects the request before we ever run, so this is a
            // guide rather than a guarantee — but showing it beats a bare 413.
            'uploadLimitBytes' => $this->uploadLimitBytes(),
        ]);
    }

    /**
     * Direct upload. Accepts a single .bin or the vendor .zip; a ZIP that carries
     * several images (a model family) becomes several rows in one go.
     */
    public function storeUpload(Request $request)
    {
        $this->authorize('manage-phone-firmware');

        $validated = $request->validate([
            'file' => 'required|file|max:'.self::MAX_UPLOAD_KB,
            'version' => 'nullable|string|max:40',
            'covers' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        // Unpacking and hashing a 150 MB package takes longer than the default
        // max_execution_time, and dying halfway looks like a blank 500.
        @set_time_limit(0);

        if ($problem = $this->storageProblem()) {
            return back()->with('error', $problem);
        }

        $file = $request->file('file');
        $original = $file->getClientOriginalName();

        try {
            $images = $this->publisher->extractImages($file->getRealPath(), $original);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $version = trim((string) ($validated['version'] ?? ''))
            ?: $this->publisher->guessVersion($original);

        if (! $version) {
            return back()->withInput()->with(
                'error',
                'Could not read a version out of "'.$original.'" — type it in the Version field and upload again.'
            );
        }

        $created = 0;
        $skipped = [];

        foreach ($images as $image) {
            $model = $this->publisher->guessModel($image['filename']) ?? 'UNKNOWN';

            if (PhoneFirmware::where('model', $model)->where('version', $version)->exists()) {
                $skipped[] = "{$model} {$version}";

                continue;
            }

            $row = PhoneFirmware::create([
                'model' => $model,
                'version' => $version,
                'filename' => $image['filename'],
                // A single-image upload can carry an explicit covers list; for a
                // multi-image ZIP each row defaults to its own model, because one
                // list cannot describe several families.
                'covers' => count($images) === 1 ? ($validated['covers'] ?? null) : null,
                'size' => $image['size'],
                'sha256' => @hash_file('sha256', $image['path']) ?: null,
                'source' => PhoneFirmware::SOURCE_UPLOAD,
                'status' => PhoneFirmware::STATUS_STORED,
                'notes' => $validated['notes'] ?? null,
                'uploaded_by' => Auth::id(),
            ]);

            $stream = fopen($image['path'], 'rb');
            if ($stream === false) {
                $row->delete();

                return back()->with('error', 'Could not read the extracted image.');
            }
            try {
                $ok = Storage::disk(PhoneFirmware::DISK)->writeStream($row->libraryPath(), $stream);
            } catch (\Throwable $e) {
                // Unguarded, this surfaced as a bare 500. The usual cause is the
                // firmware directory not being writable by the PHP-FPM user.
                Log::error('Firmware store failed: '.$e->getMessage(), ['exception' => $e]);
                $row->delete();

                return back()->with('error', 'Could not save the firmware image: '.$e->getMessage());
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($ok === false) {
                $row->delete();

                return back()->with('error', 'Storage refused the firmware image — check the disk has space and is writable.');
            }

            $row->update(['path' => $row->libraryPath()]);
            $created++;
        }

        $this->cleanupTemp($images, $file->getRealPath());

        ActivityLog::log('Phone firmware uploaded', [
            'file' => $original,
            'version' => $version,
            'images' => $created,
        ]);

        $message = "Added {$created} firmware image(s) at version {$version}.";
        if ($skipped) {
            $message .= ' Already in the library: '.implode(', ', $skipped).'.';
        }

        return redirect()->route('admin.phones.firmware.index')->with('success', $message);
    }

    /**
     * Queue a vendor URL for a server-side fetch. Grandstream packages run
     * 60–150 MB; pulling them straight onto the NOC beats pushing them up from a
     * laptop over the VPN, and dodges the nginx/PHP upload ceilings entirely.
     */
    public function storeUrl(Request $request)
    {
        $this->authorize('manage-phone-firmware');

        $validated = $request->validate([
            'source_url' => 'required|url|max:2048',
            'version' => 'nullable|string|max:40',
            'covers' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $name = basename(parse_url($validated['source_url'], PHP_URL_PATH) ?: '') ?: 'firmware.zip';
        $version = trim((string) ($validated['version'] ?? ''))
            ?: $this->publisher->guessVersion($name);

        if (! $version) {
            return back()->withInput()->with(
                'error',
                'Could not read a version out of that URL — fill in the Version field.'
            );
        }

        // Placeholder row: the model and real filename are only known once the
        // package has been fetched and unpacked, so they are stamped in by
        // firmware:fetch-remote. The unique (model, version) key means the
        // placeholder must not collide with a real row.
        $row = PhoneFirmware::create([
            'model' => 'PENDING-'.substr(md5($validated['source_url']), 0, 8),
            'version' => $version,
            'filename' => $name,
            'covers' => $validated['covers'] ?? null,
            'source' => PhoneFirmware::SOURCE_URL,
            'source_url' => $validated['source_url'],
            'status' => PhoneFirmware::STATUS_PENDING,
            'notes' => $validated['notes'] ?? null,
            'uploaded_by' => Auth::id(),
        ]);

        ActivityLog::log('Phone firmware queued from URL', [
            'id' => $row->id,
            'url' => $validated['source_url'],
        ]);

        return redirect()->route('admin.phones.firmware.index')
            ->with('success', 'Queued. Run `php artisan firmware:fetch-remote` or wait for the next scheduler tick.');
    }

    public function publish(PhoneFirmware $firmware)
    {
        $this->authorize('manage-phone-firmware');

        try {
            $this->publisher->publish($firmware);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::log('Phone firmware published', [
            'model' => $firmware->model,
            'version' => $firmware->version,
            'filename' => $firmware->filename,
        ]);

        return back()->with('success', "Publishing {$firmware->model} {$firmware->version} as {$firmware->filename}.");
    }

    public function unpublish(PhoneFirmware $firmware)
    {
        $this->authorize('manage-phone-firmware');

        $this->publisher->unpublish($firmware);

        ActivityLog::log('Phone firmware unpublished', [
            'model' => $firmware->model,
            'version' => $firmware->version,
        ]);

        return back()->with('success', "Stopped serving {$firmware->filename}. Phones keep whatever they already flashed.");
    }

    public function destroy(PhoneFirmware $firmware)
    {
        $this->authorize('manage-phone-firmware');

        $label = "{$firmware->model} {$firmware->version}";
        $this->publisher->forget($firmware);

        ActivityLog::log('Phone firmware deleted', ['firmware' => $label]);

        return back()->with('success', "Deleted {$label}.");
    }

    // ─────────────────────────────────────────────────────────────
    // Status board
    // ─────────────────────────────────────────────────────────────

    /**
     * Which phones have actually taken the firmware we publish.
     *
     * The running version comes from the phones themselves: every Grandstream
     * announces its firmware in the User-Agent it sends when it fetches
     * /phonebook.xml, and PhonebookController records it. That is fresher than
     * GDMS and needs no cloud round trip — and a phone missing from this list is
     * a phone that cannot reach the NOC at all, which is worth knowing before
     * blaming the firmware server.
     */
    public function status(Request $request)
    {
        $this->authorize('view-phone-firmware');

        $active = PhoneFirmware::active()->get();
        $reported = $this->latestReportedFirmware();

        $devices = Device::with('branch')
            ->where('type', 'phone')
            ->orderBy('model')
            ->get();

        $rows = [];

        foreach ($devices as $device) {
            $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $device->mac_address));
            $seen = $reported[$mac] ?? null;
            $running = $seen['firmware'] ?? $device->firmware_version;

            $target = $active->first(fn (PhoneFirmware $f) => $f->coversModel($device->model));

            $rows[] = [
                'mac' => $mac,
                'name' => $device->name,
                'model' => $device->model,
                'branch' => $device->branch?->name,
                'ip' => $seen['ip'] ?? $device->ip_address,
                'running' => $running,
                'target' => $target?->version,
                'target_file' => $target?->filename,
                'last_seen' => $seen['at'] ?? null,
                'state' => $this->compare($running, $target?->version),
            ];
        }

        // Phones that talk to the NOC but were never imported as assets — they
        // still upgrade, and they still belong on the board.
        $known = array_column($rows, 'mac');
        foreach ($reported as $mac => $seen) {
            if (in_array($mac, $known, true)) {
                continue;
            }
            $target = $active->first(fn (PhoneFirmware $f) => $f->coversModel($seen['model']));
            $rows[] = [
                'mac' => $mac,
                'name' => null,
                'model' => $seen['model'],
                'branch' => null,
                'ip' => $seen['ip'],
                'running' => $seen['firmware'],
                'target' => $target?->version,
                'target_file' => $target?->filename,
                'last_seen' => $seen['at'],
                'state' => $this->compare($seen['firmware'], $target?->version),
                'unmanaged' => true,
            ];
        }

        // Snapshot before filtering — the summary cards describe the fleet, not
        // whatever slice the current filter shows.
        $all = $rows;

        if ($state = $request->query('state')) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['state'] === $state));
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $needle = strtolower($q);
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(strtolower(
                $r['mac'].' '.$r['model'].' '.$r['name'].' '.$r['branch'].' '.$r['ip']
            ), $needle)));
        }

        return view('admin.phones.firmware.status', [
            'rows' => $rows,
            'active' => $active,
            'counts' => [
                'current' => count(array_filter($all, fn ($r) => $r['state'] === 'current')),
                'behind' => count(array_filter($all, fn ($r) => $r['state'] === 'behind')),
                'unknown' => count(array_filter($all, fn ($r) => $r['state'] === 'unknown')),
            ],
            'state' => $state ?? null,
            'q' => $q ?? '',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Why the firmware disk cannot be written to, or null when it is fine.
     *
     * Worth checking before unpacking a 150 MB package rather than after: the
     * common failure is the directory being owned by the deploy user while
     * PHP-FPM runs as another, and the resulting exception says nothing useful
     * to whoever is standing at the upload form.
     */
    private function storageProblem(): ?string
    {
        try {
            $root = Storage::disk(PhoneFirmware::DISK)->path('');
        } catch (\Throwable $e) {
            return 'The firmware disk is not configured: '.$e->getMessage();
        }

        if (! is_dir($root) && ! @mkdir($root, 0775, true) && ! is_dir($root)) {
            return "The firmware directory ({$root}) does not exist and could not be created. "
                .'Run deployment/firmware/setup.sh.';
        }

        if (! is_writable($root)) {
            $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($root))['name'] ?? '?') : '?';
            $me = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?';

            return "The firmware directory ({$root}) is not writable — it is owned by \"{$owner}\" "
                ."and PHP runs as \"{$me}\". Re-run deployment/firmware/setup.sh, which fixes this.";
        }

        return null;
    }

    /**
     * Smaller of PHP's two upload ceilings, in bytes. Both are PHP_INI_PERDIR and
     * set in public/.user.ini; post_max_size caps the whole body, so an
     * upload_max_filesize above it is not actually reachable.
     */
    private function uploadLimitBytes(): int
    {
        $toBytes = function (string $value): int {
            $value = trim($value);
            $unit = strtolower(substr($value, -1));
            $number = (int) $value;

            return match ($unit) {
                'g' => $number * 1024 ** 3,
                'm' => $number * 1024 ** 2,
                'k' => $number * 1024,
                default => $number,
            };
        };

        $limits = array_filter([
            $toBytes((string) ini_get('upload_max_filesize')),
            $toBytes((string) ini_get('post_max_size')),
            self::MAX_UPLOAD_KB * 1024,
        ]);

        return $limits ? (int) min($limits) : 0;
    }

    private function compare(?string $running, ?string $target): string
    {
        if (! $running || ! $target) {
            return 'unknown';
        }

        return version_compare($running, $target, '>=') ? 'current' : 'behind';
    }

    /**
     * Newest phonebook.xml check-in per MAC.
     *
     * @return array<string, array{firmware: ?string, model: ?string, ip: ?string, at: ?string}>
     */
    private function latestReportedFirmware(): array
    {
        $latestIds = PhoneRequestLog::query()
            ->whereNotNull('mac')
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('mac')
            ->pluck('id');

        return PhoneRequestLog::whereIn('id', $latestIds)
            ->get()
            ->mapWithKeys(fn (PhoneRequestLog $log) => [
                strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $log->mac)) => [
                    'firmware' => $log->firmware,
                    'model' => $log->model,
                    'ip' => $log->ip,
                    'at' => $log->created_at,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, array{path: string}>  $images
     */
    private function cleanupTemp(array $images, string $uploadPath): void
    {
        foreach ($images as $image) {
            // The single-.bin case hands back the upload itself; PHP cleans that
            // one up on shutdown, and deleting it here would be a double free.
            if ($image['path'] === $uploadPath) {
                continue;
            }
            @unlink($image['path']);
            @rmdir(dirname($image['path']));
        }
    }
}
