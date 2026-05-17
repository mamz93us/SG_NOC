<?php

namespace App\Console\Commands\EmailMarketing;

use App\Models\EmailMarketing\EmailList;
use App\Services\EmailMarketing\CsvSubscriberImporter;
use Illuminate\Console\Command;

class ImportSubscribersCommand extends Command
{
    protected $signature = 'email-marketing:import-subscribers
                            {list : EmailList id}
                            {file : Absolute path to CSV file}
                            {--email-col=0 : Index of email column}
                            {--first-name-col= : Index of first name column}
                            {--last-name-col= : Index of last name column}
                            {--skip-header=true : Skip the first row}';

    protected $description = 'Bulk import subscribers from a CSV file into an email list (CLI fallback for large imports).';

    public function handle(CsvSubscriberImporter $importer): int
    {
        $list = EmailList::find($this->argument('list'));
        if (! $list) {
            $this->error('EmailList not found.');

            return Command::FAILURE;
        }
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not readable: {$file}");

            return Command::FAILURE;
        }

        $mapping = ['email' => (int) $this->option('email-col')];
        if ($this->option('first-name-col') !== null && $this->option('first-name-col') !== '') {
            $mapping['first_name'] = (int) $this->option('first-name-col');
        }
        if ($this->option('last-name-col') !== null && $this->option('last-name-col') !== '') {
            $mapping['last_name'] = (int) $this->option('last-name-col');
        }

        $stats = $importer->import(
            $list,
            $file,
            $mapping,
            [],
            filter_var($this->option('skip-header'), FILTER_VALIDATE_BOOLEAN),
        );

        $this->info(json_encode($stats));

        return Command::SUCCESS;
    }
}
