<?php

namespace App\Jobs;

use App\Models\SyslogMessage;
use App\Services\SophosSyslogParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Walks recent rows whose source_type has a vendor parser and fills in
 * the `parsed` JSON column. Currently only Sophos is supported, but the
 * dispatcher table makes it cheap to add Cisco, UCM, etc.
 *
 * Runs every minute alongside the tagger. Operates idempotently: rows
 * that already have parsed != NULL are skipped.
 */
class ParseSyslogPayloadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // Only source_types with a registered parser are eligible.
        $parsable = ['sophos'];

        $rows = SyslogMessage::query()
            ->whereIn('source_type', $parsable)
            ->whereNull('parsed')
            ->where('received_at', '>=', now()->subHours(6))
            ->orderBy('received_at')
            ->limit(10000)
            ->get();

        if ($rows->isEmpty()) return;

        $sophosParser = app(SophosSyslogParser::class);
        $parsed = 0;
        $empty  = 0;

        foreach ($rows as $row) {
            // Prefer the untouched raw packet — rsyslog's RFC3164 parser
            // tends to misinterpret the leading device_name="…" KV pair
            // as the syslog tag, so `message` would be missing it.
            // Fall back to program+message in case `raw` is empty.
            $body = $row->raw ?: trim($row->program . ' ' . $row->message);

            $fields = match ($row->source_type) {
                'sophos' => $sophosParser->parse($body),
                default  => [],
            };

            // Store [] (empty array) for rows that didn't parse, so we
            // don't keep re-trying them every run. JSON cast keeps it
            // round-trippable.
            $row->update(['parsed' => $fields]);

            if (empty($fields)) $empty++; else $parsed++;
        }

        Log::info("ParseSyslogPayloadsJob: parsed {$parsed} rows ({$empty} empty) across " . count($parsable) . " source types.");
    }
}
