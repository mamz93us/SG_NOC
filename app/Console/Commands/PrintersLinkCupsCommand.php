<?php

namespace App\Console\Commands;

use App\Models\CupsPrinter;
use App\Models\Printer;
use Illuminate\Console\Command;

class PrintersLinkCupsCommand extends Command
{
    protected $signature = 'printers:link-cups {--dry-run : Show what would be linked without writing}';
    protected $description = 'Link cups_printers rows to printers (SNMP records) by IP address.';

    public function handle(): int
    {
        $rows = CupsPrinter::whereNull('printer_id')
            ->whereNotNull('ip_address')
            ->get();

        $matched = 0;
        $missed  = 0;

        foreach ($rows as $cups) {
            $ip = trim((string) $cups->ip_address);
            if ($ip === '') {
                $missed++;
                continue;
            }

            $printer = Printer::whereRaw('LOWER(TRIM(ip_address)) = ?', [strtolower($ip)])->first();

            if (! $printer) {
                $missed++;
                $this->line("  ✗ no SNMP printer for CUPS queue '{$cups->queue_name}' ({$ip})");
                continue;
            }

            $this->line("  ✓ linking CUPS '{$cups->queue_name}' → Printer #{$printer->id} ({$printer->printer_name})");
            $matched++;

            if (! $this->option('dry-run')) {
                $cups->printer_id = $printer->id;
                $cups->save();
            }
        }

        $this->info("Done. Matched: {$matched}, missed: {$missed}, total scanned: " . $rows->count());

        if ($this->option('dry-run')) {
            $this->warn('Dry-run — no rows were modified.');
        }

        return self::SUCCESS;
    }
}
