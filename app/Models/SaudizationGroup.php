<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One professional group the ministry sets a Saudization percentage for, with
 * the Oracle job category whose people it counts. Edited by HR on
 * Attendance ▸ Saudization; every change is in the audit trail.
 *
 * The counting is in Services\People\Saudization — nothing is stored here but
 * the targets, so a figure on the page is always today's head count.
 */
class SaudizationGroup extends Model
{
    protected $fillable = [
        'name_ar',
        'name_en',
        'job_category',
        'required_percent',
        'next_percent',
        'next_from',
        'future_percent',
        'future_from',
        'sort_order',
    ];

    protected $casts = [
        'required_percent' => 'float',
        'next_percent' => 'float',
        'future_percent' => 'float',
        'next_from' => 'date',
        'future_from' => 'date',
        'sort_order' => 'integer',
    ];

    /**
     * The percentages announced for later, soonest first.
     *
     * @return list<array{key: string, percent: float, from: ?\Illuminate\Support\Carbon}>
     */
    public function announced(): array
    {
        $steps = [];

        foreach (['next', 'future'] as $key) {
            if ($this->{$key.'_percent'} !== null) {
                $steps[] = ['key' => $key, 'percent' => (float) $this->{$key.'_percent'}, 'from' => $this->{$key.'_from'}];
            }
        }

        return $steps;
    }
}
