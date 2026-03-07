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
        Schema::table('vpn_tunnels', function (Blueprint $table) {
            $table->string('local_id')->nullable()->after('name');
            $table->string('remote_id')->nullable()->after('local_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vpn_tunnels', function (Blueprint $table) {
            $table->dropColumn(['local_id', 'remote_id']);
        });
    }
};
