<?php

namespace App\Console\Commands;

use App\Models\Attendance\BiotimeSource;
use App\Services\Attendance\BioTimeConnection;
use App\Services\Attendance\BioTimeSyncService;
use Illuminate\Console\Command;

/**
 * The Sources page's "Test connection", from the CLI: connects and shows the
 * newest rows of the source's table.
 */
class BioTimeTest extends Command
{
    protected $signature = 'biotime:test {source? : Source id or name (default: every source)}';

    protected $description = 'Connect to ZKTeco SQL Server source(s) and show a sample of their punch table';

    public function handle(BioTimeSyncService $sync): int
    {
        $wanted = $this->argument('source');
        $sources = BiotimeSource::query()
            ->when($wanted, fn ($q) => $q->where('id', $wanted)->orWhere('name', $wanted))
            ->orderBy('name')
            ->get();

        if ($sources->isEmpty()) {
            $this->warn($wanted
                ? "No source matches \"{$wanted}\"."
                : 'No sources configured — add one at /admin/attendance/sources.');

            return $wanted ? self::FAILURE : self::SUCCESS;
        }

        if (! BioTimeConnection::driverAvailable()) {
            $this->error('pdo_sqlsrv is not loaded in this PHP ('.PHP_BINARY.'). Run deployment/biotime/install-sqlsrv.sh.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($sources as $source) {
            $this->line("<info>{$source->name}</info>  {$source->source_type}  {$source->host}:{$source->port} / {$source->database} as {$source->username}");

            try {
                $result = $sync->test($source);
            } catch (\Throwable $e) {
                $failed++;
                $this->error('  '.BioTimeConnection::cleanError($e));

                continue;
            }

            $this->line("  OK in {$result['latency_ms']} ms — {$result['watermark']}");
            if ($result['clock']) {
                $this->line("  SQL Server clock: {$result['clock']['local']} local, {$result['clock']['utc']} UTC; newest row {$result['newest']}");
            }
            $this->line('  '.strtolower($result['locations_label']).': '.($result['locations'] ? implode(', ', $result['locations']) : '(none)'));
            foreach ($result['notes'] as $note) {
                $this->warn('  '.$note);
            }
            $this->table($result['columns'], $result['sample']);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
