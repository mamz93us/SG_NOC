<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_switches', function (Blueprint $table) {
            $table->unsignedBigInteger('floor_id')->nullable()->after('branch_id');
            $table->unsignedBigInteger('rack_id')->nullable()->after('floor_id');

            $table->foreign('floor_id')->references('id')->on('network_floors')->nullOnDelete();
            $table->foreign('rack_id')->references('id')->on('network_racks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('network_switches', function (Blueprint $table) {
            $table->dropForeign(['floor_id']);
            $table->dropForeign(['rack_id']);
            $table->dropColumn(['floor_id', 'rack_id']);
        });
    }
};
