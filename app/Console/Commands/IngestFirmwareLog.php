<?php

namespace App\Console\Commands;

use App\Models\PhoneFirmwareDownload;
use App\Support\GrandstreamUserAgent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Tail the firmware vhost's nginx access log into `phone_firmware_downloads`.
 *
 * nginx serves the firmware images directly — deliberately, so a 60 MB transfer
 * never occupies a PHP-FPM worker — which means Laravel never sees the request.
 * This is how the NOC answers "which phone took the firmware, and when", and it
 * is the only place a mistyped filename surfaces: a 404 here is a phone asking
 * for something that was never published.
 *
 * Same shape as smtp-relay:ingest-log — resume by persisted {inode, offset} so
 * each tick reads only new bytes and log rotation is handled, and a no-op when
 * the log is absent or unreadable (dev boxes, or before the app user is put in
 * the log's group).
 */
class IngestFirmwareLog extends Command
{
    protected $signature = 'firmware:ingest-log
        {--file= : Override the access log path (defaults to config phone_firmware.access_log)}
        {--prune : Instead of ingesting, delete downloads older than the retention window}';

    protected $description = 'Parse the firmware nginx access log into the download audit table';

    /**
     * nginx `combined`:
     *   $remote_addr - $remote_user [$time_local] "$request" $status
     *   $body_bytes_sent "$http_referer" "$http_user_agent"
     */
    private const COMBINED = '/^(?P<ip>\S+) \S+ \S+ \[(?P<time>[^\]]+)\] "(?P<request>[^"]*)" '
        .'(?P<status>\d{3}) (?P<bytes>\d+|-) "(?P<referer>[^"]*)" "(?P<agent>[^"]*)"/';

    public function handle(): int
    {
        if ($this->option('prune')) {
            return $this->prune();
        }

        $path = $this->option('file') ?: (string) config('phone_firmware.access_log');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            // Absent on dev boxes, or the app user is not yet in the log's group.
            $this->line("Firmware access log not readable ({$path}) — skipping.");

            return self::SUCCESS;
        }

        $statePath = (string) config('phone_firmware.state_path');
        File::ensureDirectoryExists(dirname($statePath));

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            $this->error("Could not open {$path}");

            return self::FAILURE;
        }

        try {
            $inode = @fstat($fh)['ino'] ?? 0;
            $size = filesize($path) ?: 0;
            $state = $this->loadState($statePath);

            // Only resume when it is the same file and it has not been truncated;
            // otherwise start from the beginning of the rotated file.
            $offset = 0;
            if (($state['inode'] ?? null) === $inode && (int) ($state['offset'] ?? 0) <= $size) {
                $offset = (int) $state['offset'];
            }
            fseek($fh, $offset);

            $cap = (int) config('phone_firmware.max_bytes_per_run', 8 * 1024 * 1024);
            $read = 0;
            $rows = 0;
            $skipped = 0;

            while (($line = fgets($fh)) !== false) {
                $read += strlen($line);

                if ($this->record(trim($line))) {
                    $rows++;
                } else {
                    $skipped++;
                }

                // Stay bounded on a huge or freshly rotated log; the next tick
                // picks up where this one stopped.
                if ($read >= $cap) {
                    $this->warn("Hit the {$cap}-byte cap for this run — continuing next tick.");
                    break;
                }
            }

            $this->saveState($statePath, ['inode' => $inode, 'offset' => ftell($fh)]);
            $this->info("Firmware log: {$rows} download(s) recorded, {$skipped} line(s) ignored.");
        } finally {
            fclose($fh);
        }

        return self::SUCCESS;
    }

    /** @return bool true when the line produced a row */
    private function record(string $line): bool
    {
        if ($line === '' || ! preg_match(self::COMBINED, $line, $m)) {
            return false;
        }

        // "GET /fw/grp2610fw.bin HTTP/1.1" → grp2610fw.bin
        $parts = explode(' ', $m['request']);
        $target = $parts[1] ?? '';
        if ($target === '') {
            return false;
        }
        $filename = basename(parse_url($target, PHP_URL_PATH) ?: '');
        if ($filename === '' || ! str_ends_with(strtolower($filename), '.bin')) {
            // Directory probes, favicon hunts, scanner noise — not a download.
            return false;
        }

        $at = $this->parseTime($m['time']);
        if ($at === null) {
            return false;
        }

        $ua = $m['agent'] === '-' ? null : $m['agent'];
        $phone = GrandstreamUserAgent::parse($ua);

        // The log line itself is the identity: same client, instant, file and
        // outcome is the same event. Guards against a lost resume offset
        // re-inserting everything.
        $hash = sha1($m['ip'].'|'.$m['time'].'|'.$target.'|'.$m['status'].'|'.$m['bytes']);

        PhoneFirmwareDownload::firstOrCreate(
            ['line_hash' => $hash],
            [
                'ip' => $m['ip'],
                'mac' => $phone['mac'],
                'model' => $phone['model'],
                'firmware_version' => $phone['firmware'],
                'filename' => $filename,
                'status' => (int) $m['status'],
                'bytes' => $m['bytes'] === '-' ? 0 : (int) $m['bytes'],
                'user_agent' => $ua ? mb_substr($ua, 0, 512) : null,
                'requested_at' => $at,
            ]
        );

        return true;
    }

    /** nginx $time_local, e.g. 25/Aug/2026:14:02:00 +0300. */
    private function parseTime(string $raw): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('d/M/Y:H:i:s O', $raw)?->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function prune(): int
    {
        $days = config('phone_firmware.retention_days');
        if (! is_numeric($days)) {
            $this->line('Retention is unlimited — nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $days);
        $deleted = PhoneFirmwareDownload::where('requested_at', '<', $cutoff)->delete();
        $this->info("Pruned {$deleted} firmware download record(s) older than {$days} days.");

        return self::SUCCESS;
    }

    private function loadState(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    private function saveState(string $path, array $state): void
    {
        @file_put_contents($path, json_encode($state));
    }
}
