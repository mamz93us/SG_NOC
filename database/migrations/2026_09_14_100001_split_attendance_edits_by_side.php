<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Check-in and check-out edits are separate from 2026-09-14: each has its own
 * reason, its own history and its own flag on the day. Until now one
 * correction could set both times under one reason, and the day carried a
 * single `adjusted` flag.
 *
 *  1. Days flagged `adjusted` get check_in_adjusted and/or check_out_adjusted,
 *     read from the correction they were built from. Locked days too: only
 *     the label changes, never a figure HR approved.
 *  2. A correction that set both times becomes two rows, one per side, each
 *     keeping the original reason, author, dates and revocation.
 *
 * Data only — no column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Relabel first: it reads the corrections before they are split.
        DB::table('attendance_days')
            ->where('flags', 'like', '%"adjusted"%')
            ->select(['id', 'flags', 'attendance_adjustment_id'])
            ->lazyById(500)
            ->each(function ($day) {
                $correction = $day->attendance_adjustment_id
                    ? DB::table('attendance_adjustments')->where('id', $day->attendance_adjustment_id)->first(['check_in', 'check_out'])
                    : null;

                // Nothing says which side was edited: the old label stays.
                if (! $correction || ($correction->check_in === null && $correction->check_out === null)) {
                    return;
                }

                $flags = array_values(array_diff(json_decode((string) $day->flags, true) ?: [], ['adjusted']));
                if ($correction->check_in !== null) {
                    $flags[] = 'check_in_adjusted';
                }
                if ($correction->check_out !== null) {
                    $flags[] = 'check_out_adjusted';
                }

                DB::table('attendance_days')->where('id', $day->id)->update(['flags' => json_encode($flags)]);
            });

        DB::table('attendance_adjustments')
            ->whereNotNull('check_in')
            ->whereNotNull('check_out')
            ->lazyById(500)
            ->each(function ($correction) {
                $checkOut = (array) $correction;
                unset($checkOut['id']);

                DB::table('attendance_adjustments')->insert(['check_in' => null] + $checkOut);
                DB::table('attendance_adjustments')->where('id', $correction->id)->update(['check_out' => null]);
            });
    }

    public function down(): void
    {
        DB::table('attendance_days')
            ->where(fn ($q) => $q->where('flags', 'like', '%"check_in_adjusted"%')->orWhere('flags', 'like', '%"check_out_adjusted"%'))
            ->select(['id', 'flags'])
            ->lazyById(500)
            ->each(function ($day) {
                $flags = json_decode((string) $day->flags, true) ?: [];
                $kept = array_values(array_diff($flags, ['check_in_adjusted', 'check_out_adjusted']));
                $kept[] = 'adjusted';

                DB::table('attendance_days')->where('id', $day->id)->update(['flags' => json_encode($kept)]);
            });
    }
};
