<?php

namespace App\Console\Commands;

use App\Services\Archive\TiffConverter;
use Illuminate\Console\Command;

/**
 * Keeps the converted-TIFF cache under its cap.
 *
 * The cache is disposable: every file in it can be rebuilt from the original,
 * so the only question is how much disk it may hold. NOC2 has about 88 GB free
 * and the archive holds ~35 GB of TIFF, so an unbounded cache is a slow way to
 * fill the server that also runs the queue, the sessions and the cache.
 */
class PruneArchiveCache extends Command
{
    protected $signature = 'archive:prune-cache {--gb= : Override the cap in GB}';

    protected $description = 'Drop the least recently used converted files until the archive cache is under its cap.';

    public function handle(TiffConverter $converter): int
    {
        $result = $converter->prune($this->option('gb') !== null ? (float) $this->option('gb') : null);

        $this->info(sprintf(
            'Cache: %s remaining, %d file(s) removed (%s freed).',
            $this->human($result['remaining_bytes']),
            $result['removed'],
            $this->human($result['freed_bytes']),
        ));

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1073741824
            ? number_format($bytes / 1073741824, 2).' GB'
            : number_format($bytes / 1048576, 1).' MB';
    }
}
