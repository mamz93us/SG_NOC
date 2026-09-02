<?php

namespace App\Console\Commands;

use App\Models\DeployRun;
use Illuminate\Console\Command;

/**
 * Close runs that never reported back.
 *
 * The Node proxy is what POSTs a run's result, so a proxy restart, a killed
 * connection or a hung command would otherwise leave the row `running`
 * forever — blocking the "one deploy at a time" guard on that server.
 *
 * Production has no queue worker, so this rides the scheduler like everything
 * else that has to actually happen.
 */
class ReapDeployRuns extends Command
{
    protected $signature = 'deploy-runs:reap';

    protected $description = 'Mark deploy runs that never reported back as timed out';

    /** Grace on top of the run's own timeout, to cover proxy round-trip. */
    private const GRACE_SECONDS = 120;

    public function handle(): int
    {
        $reaped = 0;

        DeployRun::running()
            ->whereNotNull('started_at')
            ->orderBy('id')
            ->chunkById(100, function ($runs) use (&$reaped) {
                foreach ($runs as $run) {
                    $deadline = $run->started_at->addSeconds(($run->timeout_seconds ?: 600) + self::GRACE_SECONDS);

                    if ($deadline->isFuture()) {
                        continue;
                    }

                    $run->finish(
                        'timeout',
                        null,
                        trim(($run->output ?? '')."\n\n… no result was reported; marked timed out by deploy-runs:reap …\n")
                    );

                    $this->warn("Run #{$run->id} timed out ({$run->timeout_seconds}s + grace).");
                    $reaped++;
                }
            });

        $this->info($reaped === 0 ? 'No stuck runs.' : "Reaped {$reaped} stuck run(s).");

        return self::SUCCESS;
    }
}
