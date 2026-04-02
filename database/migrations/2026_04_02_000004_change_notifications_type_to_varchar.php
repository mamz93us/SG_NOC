<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change notifications.type from ENUM (fixed list) to VARCHAR(100).
 * This allows new notification types to be added without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL: modify ENUM → VARCHAR in-place
        DB::statement("ALTER TABLE notifications MODIFY COLUMN `type` VARCHAR(100) NOT NULL");
        // Re-add the index that was on the ENUM column
        // (MySQL drops the index when we change type, so we recreate it)
        Schema::table('notifications', function (Blueprint $table) {
            // Only add index if not already present
            try {
                $table->index('type', 'notifications_type_index');
            } catch (\Throwable) {
                // Index may already exist — safe to ignore
            }
        });
    }

    public function down(): void
    {
        // Revert to original ENUM (existing rows that use new types will be truncated)
        DB::statement("ALTER TABLE notifications MODIFY COLUMN `type` ENUM(
            'approval_request','approval_action',
            'workflow_complete','workflow_failed',
            'system_alert','noc_alert','printer_maintenance'
        ) NOT NULL");
    }
};
