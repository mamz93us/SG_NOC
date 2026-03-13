<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update 'type' enum to include ITAM equipment types
        // Note: Using raw SQL because change() on enums is unreliable in some Laravel versions/DB drivers
        DB::statement("ALTER TABLE devices MODIFY COLUMN type ENUM(
            'ucm', 'switch', 'router', 'firewall', 'ap', 'printer', 'server', 
            'laptop', 'desktop', 'monitor', 'keyboard', 'mouse', 'headset', 'tablet', 'other'
        ) NOT NULL");

        // Update 'source' enum to include 'azure'
        DB::statement("ALTER TABLE devices MODIFY COLUMN source ENUM(
            'manual', 'meraki', 'ucm', 'printer', 'azure'
        ) DEFAULT 'manual' NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original limited sets
        DB::statement("ALTER TABLE devices MODIFY COLUMN type ENUM(
            'ucm', 'switch', 'router', 'firewall', 'ap', 'printer', 'server', 'other'
        ) NOT NULL");

        DB::statement("ALTER TABLE devices MODIFY COLUMN source ENUM(
            'manual', 'meraki', 'ucm', 'printer'
        ) DEFAULT 'manual' NOT NULL");
    }
};
