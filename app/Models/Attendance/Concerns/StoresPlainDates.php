<?php

namespace App\Models\Attendance\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Writes the columns in $plainDates as a bare 'Y-m-d'.
 *
 * The `date` cast alone writes "Y-m-d 00:00:00". MySQL's DATE column hides
 * that, but it breaks `where('work_date', $date)` lookups on anything else,
 * and the attendance code looks days up by date constantly.
 */
trait StoresPlainDates
{
    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->plainDates, true) && $value !== null && $value !== '') {
            $this->attributes[$key] = $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : CarbonImmutable::parse($value)->toDateString();

            return $this;
        }

        return parent::setAttribute($key, $value);
    }
}
