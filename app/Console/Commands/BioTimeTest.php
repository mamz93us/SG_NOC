<?php

namespace App\Console\Commands;

use App\Models\Attendance\BiotimeSource;
use App\Services\Attendance\BioTimeConnection;
use Illuminate\Console\Command;

/**
 * The Sources page's "Test connection", from the CLI: connects and runs the
 * ingest query with TOP 5.
 */
class BioTimeTest extends Command
{
    protected $signature = 'biotime:test {source? : Source id or name (default: every source)}';

    protected $description = 'Connect to BioTime SQL Server source(s) and show a sample of iclock_transaction';

    public function handle(BioTimeConnection $bioTime): int
    {
        $wanted = $this->argument('source');
        $sources = BiotimeSource::query()
            ->when($wanted, fn ($q) => $q->where('id', $wanted)->orWhere('name', $wanted))
            ->orderBy('name')
            ->get();

        if ($sources->isEmpty()) {
            $this->warn($wanted
                ? "No BioTime source matches \"{$wanted}\"."
                : 'No BioTime sources configured — add one at /admin/attendance/sources.');

            return $wanted ? self::FAILURE : self::SUCCESS;
        }

        if (! BioTimeConnection::driverAvailable()) {
            $this->error('pdo_sqlsrv is not loaded in this PHP ('.PHP_BINARY.'). Run deployment/biotime/install-sqlsrv.sh.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($sources as $source) {
            $this->line("<info>{$source->name}</info>  {$source->host}:{$source->port} / {$source->database} as {$source->username}");

            try {
                $result = $bioTime->test($source);
            } catch (\Throwable $e) {
                $failed++;
                $this->error('  '.BioTimeConnection::cleanError($e));

                continue;
            }

            $this->line("  OK in {$result['latency_ms']} ms — max id {$result['max_id']}");
            $this->line('  areas: '.($result['areas'] ? implode(', ', $result['areas']) : '(none)'));
            $this->table(BioTimeConnection::COLUMNS, $result['sample']);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
