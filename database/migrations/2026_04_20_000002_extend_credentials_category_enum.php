<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE credentials MODIFY COLUMN category ENUM('admin','api','snmp','user','service','telnet','enable','other') NOT NULL DEFAULT 'other'");
    }

    public function down(): void
    {
        DB::statement("UPDATE credentials SET category = 'other' WHERE category IN ('telnet','enable')");
        DB::statement("ALTER TABLE credentials MODIFY COLUMN category ENUM('admin','api','snmp','user','service','other') NOT NULL DEFAULT 'other'");
    }
};
