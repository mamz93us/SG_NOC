<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * source_type was enum('voice','switch'), but SwitchPollMlsQos writes
 * 'switch-qos' — MySQL truncates the value and the insert fails, so every
 * QoS-drop alert was silently lost (log: "Data truncated for column
 * 'source_type'"). Convert to a plain varchar so new alert sources never
 * need a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `vq_alert_events` MODIFY COLUMN `source_type` VARCHAR(32) NOT NULL");
        }
        // SQLite (tests) stores enums as TEXT with a CHECK we can't easily drop —
        // and it never enforced the truncation anyway, so nothing to do there.
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `vq_alert_events` MODIFY COLUMN `source_type` ENUM('voice','switch','switch-qos') NOT NULL");
        }
    }
};
