<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovery_scans', function (Blueprint $table) {
            // When true, the background scan processor auto-creates + polls every
            // printer it finds (used by the Printers "Discover Printers" button).
            $table->boolean('auto_import_printers')->default(false)->after('snmp_timeout');
            $table->unsignedSmallInteger('imported_count')->default(0)->after('reachable_count');
        });
    }

    public function down(): void
    {
        Schema::table('discovery_scans', function (Blueprint $table) {
            $table->dropColumn(['auto_import_printers', 'imported_count']);
        });
    }
};
