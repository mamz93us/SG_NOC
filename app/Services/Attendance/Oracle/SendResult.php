<?php

namespace App\Services\Attendance\Oracle;

use App\Models\Attendance\AttendanceExport;

/** What a sender made of one payload. */
final class SendResult
{
    public function __construct(
        /** AttendanceExport::PREPARED | SENT | FAILED */
        public readonly string $status,
        public readonly string $message,
        /** Oracle's own id for the batch, when it gives one. */
        public readonly ?string $reference = null,
        /** The raw response, for the export record. */
        public readonly ?string $response = null,
    ) {}

    public static function failed(string $message, ?string $response = null): self
    {
        return new self(AttendanceExport::FAILED, $message, null, $response);
    }
}
