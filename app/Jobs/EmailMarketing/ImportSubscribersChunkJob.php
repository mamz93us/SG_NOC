<?php

namespace App\Jobs\EmailMarketing;

use App\Models\EmailMarketing\EmailList;
use App\Services\EmailMarketing\CsvSubscriberImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Used by the CLI command for big CSV files. The web import flow
 * runs the importer inline because users are waiting on results.
 */
class ImportSubscribersChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public int $listId,
        public string $filePath,
        public array $mapping,
        public array $attrColumnNames = [],
        public bool $skipHeader = true,
        public ?int $userId = null,
    ) {}

    public function handle(CsvSubscriberImporter $importer): void
    {
        $list = EmailList::find($this->listId);
        if (! $list) {
            return;
        }

        $importer->import($list, $this->filePath, $this->mapping, $this->attrColumnNames, $this->skipHeader, $this->userId);
    }
}
