<?php

namespace App\Services\Attendance\Oracle;

use App\Models\Attendance\AttendanceExport;

/**
 * Stands in until Oracle publishes its attendance API: sends nothing, and
 * leaves the export "prepared" so HR can download the payload as CSV or JSON.
 */
class StubOracleAttendanceSender implements OracleAttendanceSender
{
    public function send(array $payload): SendResult
    {
        return new SendResult(
            AttendanceExport::PREPARED,
            'Not sent — the Oracle attendance API is not connected yet. The payload is stored: download it as CSV or JSON.',
        );
    }
}
