<?php

namespace App\Jobs;

use App\Models\SyslogMessage;
use App\Services\AsteriskSyslogParser;
use App\Services\SophosSyslogParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Walks recent rows whose source_type has a vendor parser and fills in
 * the `parsed` JSON column. Currently only Sophos is supported, but the
 * dispatcher table makes it cheap to add Cisco, UCM, etc.
 *
 * Runs every minute alongside the tagger. Operates idempotently: rows
 * that already have parsed != NULL are skipped.
 *
 * Bulk strategy:
 *   - Pull a large window of unprocessed rows in one SELECT (no Eloquent
 *     hydration; raw rowsets are 10x cheaper than models for this).
 *   - Parse in PHP.
 *   - UPDATE in batches of 1000 via a single CASE WHEN statement, so
 *     each batch is one round-trip instead of 1000.
 */
class ParseSyslogPayloadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Max rows pulled into memory per job run. Tune if memory pressure. */
    private const BATCH = 25000;

    /** Rows per UPDATE … CASE WHEN statement. Keep packet size sane. */
    private const UPDATE_CHUNK = 1000;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $parsable = ['sophos', 'ucm'];

        $rows = DB::table('syslog_messages')
            ->select(['id', 'source_type', 'raw', 'program', 'message'])
            ->whereIn('source_type', $parsable)
            ->whereNull('parsed')
            ->where('received_at', '>=', now()->subHours(6))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        if ($rows->isEmpty()) return;

        $started  = microtime(true);
        $sophos   = app(SophosSyslogParser::class);
        $asterisk = app(AsteriskSyslogParser::class);

        // id => json string. We pre-encode here so the DB layer doesn't
        // re-encode on every row.
        $pairs = [];
        $parsed = 0;
        $empty  = 0;

        foreach ($rows as $row) {
            // Prefer the untouched raw packet — rsyslog's RFC3164 parser
            // misinterprets the leading device_name="…" KV pair as the
            // syslog tag, so `message` is missing it. Fall back to
            // program+message when raw is empty.
            $body = $row->raw !== null && $row->raw !== ''
                ? $row->raw
                : trim(($row->program ?? '') . ' ' . ($row->message ?? ''));

            $fields = match ($row->source_type) {
                'sophos' => $sophos->parse($body),
                'ucm'    => $asterisk->parse($body),
                default  => [],
            };

            $pairs[(int) $row->id] = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (empty($fields)) $empty++; else $parsed++;
        }

        // Bulk UPDATE in chunks. One round-trip per chunk instead of
        // one per row.
        foreach (array_chunk($pairs, self::UPDATE_CHUNK, true) as $chunk) {
            $this->bulkUpdate($chunk);
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        Log::info("ParseSyslogPayloadsJob: parsed {$parsed} rows ({$empty} empty) in {$ms}ms.");
    }

    /**
     * Run one UPDATE … SET parsed = CASE id WHEN ? THEN ? … END WHERE id IN (…)
     * with prepared bindings.
     */
    private function bulkUpdate(array $pairs): void
    {
        if (empty($pairs)) return;

        $ids = array_keys($pairs);
        $idList = implode(',', array_map('intval', $ids));   // safe: ints

        $caseSql  = '';
        $bindings = [];
        foreach ($pairs as $id => $json) {
            $caseSql   .= 'WHEN ? THEN ? ';
            $bindings[] = $id;
            $bindings[] = $json;
        }

        DB::statement(
            "UPDATE syslog_messages SET parsed = CASE id {$caseSql} END WHERE id IN ({$idList})",
            $bindings
        );
    }
}
