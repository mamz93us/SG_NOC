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
        // 1. Update 'type' enum for devices
        DB::statement("ALTER TABLE devices MODIFY COLUMN type ENUM(
            'ucm', 'switch', 'router', 'firewall', 'ap', 'printer', 'server', 
            'laptop', 'desktop', 'monitor', 'keyboard', 'mouse', 'headset', 'tablet', 'other'
        ) NOT NULL");

        // 2. Update 'source' enum for devices
        DB::statement("ALTER TABLE devices MODIFY COLUMN source ENUM(
            'manual', 'meraki', 'ucm', 'printer', 'azure'
        ) DEFAULT 'manual' NOT NULL");

        // 3. Update 'condition' enum for employee_assets to include 'used'
        DB::statement("ALTER TABLE employee_assets MODIFY COLUMN `condition` ENUM(
            'new', 'used', 'refurbished', 'damaged', 'good', 'fair', 'poor'
        ) DEFAULT 'good' NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE employee_assets MODIFY COLUMN `condition` ENUM(
            'good', 'fair', 'poor'
        ) DEFAULT 'good' NOT NULL");

        DB::statement("ALTER TABLE devices MODIFY COLUMN source ENUM(
            'manual', 'meraki', 'ucm', 'printer'
        ) DEFAULT 'manual' NOT NULL");

        DB::statement("ALTER TABLE devices MODIFY COLUMN type ENUM(
            'ucm', 'switch', 'router', 'firewall', 'ap', 'printer', 'server', 'other'
        ) NOT NULL");
    }
};
