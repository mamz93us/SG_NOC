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
        // Expand enums. We use DB::statement for reliability with ENUM types.
        
        // 1. Devices: Add ITAM equipment types
        DB::statement("ALTER TABLE devices MODIFY COLUMN type ENUM(
            'ucm', 'switch', 'router', 'firewall', 'ap', 'printer', 'server', 
            'laptop', 'desktop', 'monitor', 'keyboard', 'mouse', 'headset', 'tablet', 'other'
        ) NOT NULL");

        // 2. Devices: Add 'azure' source
        DB::statement("ALTER TABLE devices MODIFY COLUMN source ENUM(
            'manual', 'meraki', 'ucm', 'printer', 'azure'
        ) DEFAULT 'manual' NOT NULL");

        // 3. Employee Assets: Add 'new', 'used', etc.
        DB::statement("ALTER TABLE employee_assets MODIFY COLUMN `condition` ENUM(
            'new', 'used', 'refurbished', 'damaged', 'good', 'fair', 'poor'
        ) DEFAULT 'good' NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We do not shrink enums in down() because it causes data truncation errors 
        // if the new types are already in use. We keep the expanded types.
    }
};
