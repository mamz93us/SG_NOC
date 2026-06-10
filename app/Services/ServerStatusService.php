<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Gathers the live host + application health snapshot for the Admin →
 * Server Status page: CPU load, memory, disks, uptime, systemd service
 * states, docker containers, and Laravel-level checks (DB, queue depth,
 * scheduler heartbeat). Every probe is individually guarded so a missing
 * binary (local Windows dev, stripped PATH) degrades to "unavailable"
 * instead of erroring the page.
 */
class ServerStatusService
{
    public function snapshot(): array
    {
        return [
            'host' => $this->host(),
            'cpu' => $this->cpu(),
            'memory' => $this->memory(),
            'disks' => $this->disks(),
            'services' => $this->services(),
            'docker' => $this->dockerContainers(),
            'app' => $this->appHealth(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // ─── Host ─────────────────────────────────────────────────────

    private function host(): array
    {
        $uptimeSeconds = null;
        if (is_readable('/proc/uptime')) {
            $uptimeSeconds = (int) floatval(explode(' ', (string) file_get_contents('/proc/uptime'))[0] ?? 0);
        }

        return [
            'hostname' => gethostname() ?: 'unknown',
            'os' => php_uname('s').' '.php_uname('r'),
            'arch' => php_uname('m'),
            'uptime_seconds' => $uptimeSeconds,
            'uptime_human' => $uptimeSeconds ? $this->humanDuration($uptimeSeconds) : null,
            'server_time' => now()->format('Y-m-d H:i:s T'),
        ];
    }

    // ─── CPU ──────────────────────────────────────────────────────

    private function cpu(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        $cores = null;
        if (is_readable('/proc/cpuinfo')) {
            $cores = max(1, (int) preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo')));
        }

        return [
            'cores' => $cores,
            'load_1' => $load !== false ? round($load[0], 2) : null,
            'load_5' => $load !== false ? round($load[1], 2) : null,
            'load_15' => $load !== false ? round($load[2], 2) : null,
            // load relative to core count, as a 0-100+ percentage for the bar
            'load_percent' => ($load !== false && $cores) ? (int) min(150, round($load[0] / $cores * 100)) : null,
        ];
    }

    // ─── Memory ───────────────────────────────────────────────────

    private function memory(): array
    {
        if (! is_readable('/proc/meminfo')) {
            return ['available' => false];
        }

        $info = [];
        foreach (explode("\n", (string) file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
                $info[$m[1]] = (int) $m[2] * 1024;
            }
        }

        $total = $info['MemTotal'] ?? 0;
        $availableMem = $info['MemAvailable'] ?? 0;
        $used = max(0, $total - $availableMem);
        $swapTotal = $info['SwapTotal'] ?? 0;
        $swapUsed = max(0, $swapTotal - ($info['SwapFree'] ?? 0));

        return [
            'available' => $total > 0,
            'total' => $total,
            'used' => $used,
            'free' => $availableMem,
            'percent' => $total > 0 ? (int) round($used / $total * 100) : null,
            'total_human' => $this->humanBytes($total),
            'used_human' => $this->humanBytes($used),
            'swap_total' => $swapTotal,
            'swap_used' => $swapUsed,
            'swap_percent' => $swapTotal > 0 ? (int) round($swapUsed / $swapTotal * 100) : null,
            'swap_total_human' => $this->humanBytes($swapTotal),
            'swap_used_human' => $this->humanBytes($swapUsed),
        ];
    }

    // ─── Disks ────────────────────────────────────────────────────

    private function disks(): array
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $args = ['df', '-PB1'];
            foreach ((array) config('server_status.df_exclude_types', []) as $type) {
                $args[] = '-x';
                $args[] = $type;
            }
            $out = $this->exec($args);
            if ($out !== null) {
                $disks = [];
                foreach (array_slice(explode("\n", trim($out)), 1) as $line) {
                    $parts = preg_split('/\s+/', trim($line), 6);
                    if (count($parts) < 6) {
                        continue;
                    }
                    [$fs, $total, $used, $free, , $mount] = $parts;
                    if ((int) $total <= 0) {
                        continue;
                    }
                    $disks[] = [
                        'filesystem' => $fs,
                        'mount' => $mount,
                        'total' => (int) $total,
                        'used' => (int) $used,
                        'free' => (int) $free,
                        'percent' => (int) round((int) $used / (int) $total * 100),
                        'total_human' => $this->humanBytes((int) $total),
                        'used_human' => $this->humanBytes((int) $used),
                        'free_human' => $this->humanBytes((int) $free),
                    ];
                }
                if ($disks !== []) {
                    return $disks;
                }
            }
        }

        // Fallback (Windows dev / df missing): just the app's volume.
        $total = (float) @disk_total_space(base_path());
        $free = (float) @disk_free_space(base_path());
        if ($total <= 0) {
            return [];
        }
        $used = $total - $free;

        return [[
            'filesystem' => base_path(),
            'mount' => base_path(),
            'total' => (int) $total,
            'used' => (int) $used,
            'free' => (int) $free,
            'percent' => (int) round($used / $total * 100),
            'total_human' => $this->humanBytes((int) $total),
            'used_human' => $this->humanBytes((int) $used),
            'free_human' => $this->humanBytes((int) $free),
        ]];
    }

    // ─── systemd services ─────────────────────────────────────────

    private function services(): array
    {
        $units = (array) config('server_status.services', []);
        if ($units === [] || PHP_OS_FAMILY !== 'Linux') {
            return array_map(fn ($u) => ['unit' => $u, 'state' => 'unavailable'], $units);
        }

        $services = [];
        foreach ($units as $unit) {
            $state = $this->exec(['systemctl', 'is-active', $unit], allowFailure: true);
            $services[] = [
                'unit' => $unit,
                // systemctl prints: active | inactive | failed | activating | (unknown unit → inactive + stderr)
                'state' => $state !== null ? (trim($state) ?: 'unknown') : 'unavailable',
            ];
        }

        return $services;
    }

    // ─── Docker containers ────────────────────────────────────────

    private function dockerContainers(): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return [];
        }

        $out = $this->exec(['docker', 'ps', '-a', '--format', '{{.Names}}|{{.Status}}|{{.Image}}'], allowFailure: true);
        if ($out === null || trim($out) === '') {
            return [];
        }

        $containers = [];
        foreach (explode("\n", trim($out)) as $line) {
            $parts = explode('|', $line, 3);
            if (count($parts) < 2) {
                continue;
            }
            $containers[] = [
                'name' => $parts[0],
                'status' => $parts[1],
                'image' => $parts[2] ?? '',
                'running' => str_starts_with($parts[1], 'Up'),
            ];
        }

        return $containers;
    }

    // ─── Application health ───────────────────────────────────────

    private function appHealth(): array
    {
        $db = ['connected' => false, 'version' => null, 'size' => null, 'size_human' => null, 'error' => null];
        try {
            $db['version'] = (string) (DB::select('select version() as v')[0]->v ?? '');
            $size = DB::table('information_schema.tables')
                ->whereRaw('table_schema = DATABASE()')
                ->sum(DB::raw('data_length + index_length'));
            $db['connected'] = true;
            $db['size'] = (int) $size;
            $db['size_human'] = $this->humanBytes((int) $size);
        } catch (\Throwable $e) {
            $db['error'] = $e->getMessage();
        }

        $queuePending = $queueFailed = null;
        try {
            $queuePending = DB::table('jobs')->count();
            $queueFailed = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
        }

        $schedulerLast = null;
        try {
            $schedulerLast = cache('scheduler:last_run');
        } catch (\Throwable) {
        }

        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => (string) config('app.env'),
            'debug' => (bool) config('app.debug'),
            'db' => $db,
            'queue_pending' => $queuePending,
            'queue_failed' => $queueFailed,
            'queue_driver' => (string) config('queue.default'),
            'cache_driver' => (string) config('cache.default'),
            'session_driver' => (string) config('session.driver'),
            'scheduler_last_run' => $schedulerLast,
            // > 3 min without a heartbeat = supervisor/cron loop is down
            'scheduler_healthy' => $schedulerLast !== null
                && now()->diffInSeconds(\Illuminate\Support\Carbon::parse($schedulerLast)) < 180,
            'storage_writable' => is_writable(storage_path()),
        ];
    }

    // ─── helpers ──────────────────────────────────────────────────

    /** @param  list<string>  $command */
    private function exec(array $command, bool $allowFailure = false): ?string
    {
        try {
            $process = new Process($command);
            $process->setTimeout(8);
            $process->run();
            if (! $process->isSuccessful() && ! $allowFailure) {
                return null;
            }

            return $process->getOutput() !== '' ? $process->getOutput() : trim($process->getErrorOutput());
        } catch (\Throwable) {
            return null;
        }
    }

    private function humanBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }

    private function humanDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return ($days > 0 ? "{$days}d " : '')."{$hours}h {$minutes}m";
    }
}
