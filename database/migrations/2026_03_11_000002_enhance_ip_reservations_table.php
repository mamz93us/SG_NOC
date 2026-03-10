<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_reservations', function (Blueprint $table) {
            $table->foreignId('subnet_id')->nullable()->after('branch_id')
                  ->constrained('ipam_subnets')->nullOnDelete();
            $table->string('status')->default('reserved')->after('notes');       // available, reserved, dhcp, static, conflict, offline
            $table->string('source')->default('manual')->after('status');        // manual, meraki, sophos, snmp
            $table->foreignId('device_id')->nullable()->after('source')
                  ->constrained('devices')->nullOnDelete();
            $table->timestamp('last_seen')->nullable()->after('device_id');
        });

        // Backfill existing rows
        DB::table('ip_reservations')->whereNull('status')->orWhere('status', 'reserved')->update([
            'status' => 'static',
            'source' => 'manual',
        ]);
    }

    public function down(): void
    {
        Schema::table('ip_reservations', function (Blueprint $table) {
            $table->dropForeign(['subnet_id']);
            $table->dropForeign(['device_id']);
            $table->dropColumn(['subnet_id', 'status', 'source', 'device_id', 'last_seen']);
        });
    }
};
