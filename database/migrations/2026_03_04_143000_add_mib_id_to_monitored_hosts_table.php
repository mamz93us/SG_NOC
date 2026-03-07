<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->foreignId('mib_id')->nullable()->after('snmp_port')->constrained('mibs')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mib_id');
        });
    }
};
