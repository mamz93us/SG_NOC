<?php

namespace App\Services\Attendance\Readers;

use App\Models\Attendance\BiotimeSource;

/** The reader for a source's kind of table. */
class PunchReaders
{
    public function for(BiotimeSource $source): PunchReader
    {
        return match ($source->source_type) {
            BiotimeSource::TYPE_ACCESS => new AccessTransactionReader,
            default => new IclockTransactionReader,
        };
    }
}
