<?php

namespace App\Services\Attendance\Oracle;

/**
 * Delivers an approved period's payload to Oracle.
 *
 * The Oracle attendance API does not exist yet, so the configured sender is
 * StubOracleAttendanceSender. The real one implements this and is named in
 * config/attendance.php — nothing else changes.
 */
interface OracleAttendanceSender
{
    /**
     * @param  array{period: array<string, mixed>, generated_at: string, record_count: int, records: list<array<string, mixed>>}  $payload
     */
    public function send(array $payload): SendResult;
}
