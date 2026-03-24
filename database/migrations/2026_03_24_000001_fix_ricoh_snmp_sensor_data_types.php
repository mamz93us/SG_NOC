<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix Ricoh SNMP sensor data_types:
 *
 * 1. Page-counter sensors (Ricoh Private MIB .19.*) were stored as
 *    data_type='counter' which calculates a rate (pages/sec) — always 0
 *    when the printer isn't actively printing.  Change to 'absolute_counter'
 *    so the raw cumulative page count (e.g. 827,244) is stored directly.
 *
 * 2. Toner sensors (Ricoh Private MIB .24.1.1.5.*) stored as data_type='gauge'
 *    don't get Ricoh negative-value normalisation.  Change to 'toner_gauge'.
 *
 * 3. Reset last_raw_counter so the next poll stores the fresh absolute value
 *    rather than trying to compute a delta from an old reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fix page counter sensors (Ricoh counter MIB subtree)
        DB::table('snmp_sensors')
            ->where('data_type', 'counter')
            ->where(function ($q) {
                $q->where('oid', 'like', '1.3.6.1.4.1.367.3.2.1.2.19%')
                  ->orWhere('sensor_group', 'Counters');
            })
            ->update([
                'data_type'       => 'absolute_counter',
                'last_raw_counter' => null,
            ]);

        // Fix toner / consumable sensors
        DB::table('snmp_sensors')
            ->where('data_type', 'gauge')
            ->where(function ($q) {
                $q->where('oid', 'like', '1.3.6.1.4.1.367.3.2.1.2.24%')
                  ->orWhereIn('sensor_group', ['Toner', 'Consumables']);
            })
            ->update(['data_type' => 'toner_gauge']);
    }

    public function down(): void
    {
        DB::table('snmp_sensors')
            ->where('data_type', 'absolute_counter')
            ->where('oid', 'like', '1.3.6.1.4.1.367.3.2.1.2.19%')
            ->update(['data_type' => 'counter']);

        DB::table('snmp_sensors')
            ->where('data_type', 'toner_gauge')
            ->update(['data_type' => 'gauge']);
    }
};
