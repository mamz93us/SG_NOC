<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // sqlite (local/test) has no ENUM constraint to widen
        }

        DB::statement("ALTER TABLE devices MODIFY COLUMN source ENUM(
            'manual', 'meraki', 'ucm', 'printer', 'azure', 'gdms', 'access_point'
        ) NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('devices')->where('source', 'access_point')->update(['source' => 'manual']);

        DB::statement("ALTER TABLE devices MODIFY COLUMN source ENUM(
            'manual', 'meraki', 'ucm', 'printer', 'azure', 'gdms'
        ) NULL");
    }
};
