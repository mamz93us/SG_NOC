<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand the type ENUM to include user-equipment categories
        DB::statement("ALTER TABLE devices MODIFY COLUMN type ENUM(
            'ucm','switch','router','firewall','ap','printer','server',
            'laptop','desktop','monitor','keyboard','mouse','headset','tablet',
            'other'
        ) NOT NULL");

        // Expand the status ENUM to include available/assigned (used by employee asset flow)
        DB::statement("ALTER TABLE devices MODIFY COLUMN status ENUM(
            'active','available','assigned','maintenance','retired'
        ) NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE devices MODIFY COLUMN type ENUM(
            'ucm','switch','router','firewall','ap','printer','server','other'
        ) NOT NULL");

        DB::statement("ALTER TABLE devices MODIFY COLUMN status ENUM(
            'active','retired','maintenance'
        ) NOT NULL DEFAULT 'active'");
    }
};
