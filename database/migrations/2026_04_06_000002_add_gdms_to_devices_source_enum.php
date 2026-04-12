<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE devices MODIFY COLUMN source ENUM('manual','meraki','ucm','printer','azure','gdms') NULL");
    }

    public function down(): void
    {
        // Update any gdms rows before removing the value
        DB::table('devices')->where('source', 'gdms')->update(['source' => 'manual']);
        DB::statement("ALTER TABLE devices MODIFY COLUMN source ENUM('manual','meraki','ucm','printer','azure') NULL");
    }
};
