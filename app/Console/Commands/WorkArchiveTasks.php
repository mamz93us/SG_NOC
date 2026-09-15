<?php

namespace App\Console\Commands;

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveSource;
use App\Models\Archive\ArchiveTask;
use App\Services\Archive\ArcMate\ArcMateDiscovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Does the work the archive pages ask for but must never do themselves.
 *
 * The rule this exists to keep: a page queues an ArchiveTask and returns. Doing
 * this inline is what turned the attendance screens into 504s — a PHP-FPM
 * worker held for minutes while nginx gave up — and the archive has heavier
 * buttons than attendance ever had (verify a sample of transferred files,
 * retry failures, recount half a million documents).
 *
 * Handlers are added as the phases land; an unknown type fails loudly rather
 * than silently vanishing, so a queued button never looks like it worked.
 */
class WorkArchiveTasks extends Command
{
    protected $signature = 'archive:work {--max-seconds=50 : Stop cleanly after this long}';

    protected $description = 'Run queued document archive tasks (rescan, recount, verification, retries).';

    public function handle(): int
    {
        @set_time_limit(0);

        $deadline = microtime(true) + max(5, (int) $this->option('max-seconds'));
        $done = 0;

        while (microtime(true) < $deadline) {
            /** @var ArchiveTask|null $task */
            $task = ArchiveTask::pending()->orderBy('id')->first();

            if (! $task) {
                break;
            }

            $task->markRunning();

            try {
                $result = $this->runTask($task);
                $task->markDone($result);
                $done++;
                $this->line($task->type.': '.json_encode($result));
            } catch (\Throwable $e) {
                $task->markFailed($e->getMessage());
                $this->error($task->type.': '.$e->getMessage());
                Log::error('[archive:work] '.$task->type.': '.$e->getMessage());
            }
        }

        if ($done > 0) {
            $this->info("Ran {$done} archive task(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Dispatch one task to its handler.
     *
     * Not named run(): Illuminate\Console\Command inherits a public run() from
     * Symfony, and redeclaring it private is a fatal error at class load — which
     * takes the whole console down, not just this command.
     *
     * @return array<string,mixed>
     */
    private function runTask(ArchiveTask $task): array
    {
        return match ($task->type) {
            ArchiveTask::TYPE_RECOUNT => $this->recount($task),
            ArchiveTask::TYPE_RESCAN_SOURCE => $this->rescan(),
            default => throw new \RuntimeException("No handler for archive task type [{$task->type}]."),
        };
    }

    /**
     * Recount one archive, or all of them.
     *
     * Counts are shown on every archive card, so they are stored rather than
     * counted per page load: counting 513,381 documents on each visit would be
     * a self-inflicted slow page.
     *
     * @return array<string,mixed>
     */
    private function recount(ArchiveTask $task): array
    {
        $archives = Archive::query()
            ->when($task->params['archive_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->get();

        $counted = [];

        foreach ($archives as $archive) {
            $documents = DB::table('archive_documents')
                ->where('archive_id', $archive->getKey())
                ->where('status', ArchiveDocument::STATUS_ACTIVE)
                ->whereNull('deleted_at')
                ->count();

            $files = DB::table('archive_files')->where('archive_id', $archive->getKey())->count();
            $bytes = (int) DB::table('archive_files')->where('archive_id', $archive->getKey())->sum('size');

            $archive->forceFill([
                'document_count' => $documents,
                'file_count' => $files,
                'byte_total' => $bytes,
                'counts_updated_at' => now(),
            ])->save();

            $counted[$archive->slug] = ['documents' => $documents, 'files' => $files];
        }

        return $counted;
    }

    /**
     * Re-read the share and report what ArcMate says about each project now.
     *
     * Reports only — nothing is created or changed here. Enabling an archive is
     * a decision a person makes on the manage page, because it is also a
     * decision to mirror half a million documents.
     *
     * @return array<string,mixed>
     */
    private function rescan(): array
    {
        $source = ArchiveSource::query()->enabled()->first();

        if (! $source) {
            return ['projects' => 0, 'note' => 'No ArcMate source configured.'];
        }

        $discovery = new ArcMateDiscovery($source);

        if (! $discovery->mountAvailable()) {
            return ['projects' => 0, 'note' => 'The ArcMate share is not mounted at '.$source->mountPath().'.'];
        }

        $projects = $discovery->scan();

        return [
            'projects' => count($projects),
            'encrypted' => count(array_filter($projects, fn (array $p) => $p['encrypted'])),
            'folders' => array_column($projects, 'folder'),
        ];
    }
}
