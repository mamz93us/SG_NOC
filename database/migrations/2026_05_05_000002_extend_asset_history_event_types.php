<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE asset_history MODIFY COLUMN event_type ENUM(
            'created',
            'assigned',
            'returned',
            'maintenance',
            'repair',
            'retired',
            'disposed',
            'license_assigned',
            'license_removed',
            'note_added',
            'transferred',
            'moved_to_storage',
            'scrap_requested',
            'scrap_approved',
            'scrap_rejected',
            'scrapped'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE asset_history MODIFY COLUMN event_type ENUM(
            'created',
            'assigned',
            'returned',
            'maintenance',
            'repair',
            'retired',
            'disposed',
            'license_assigned',
            'license_removed',
            'note_added'
        ) NOT NULL");
    }
};
