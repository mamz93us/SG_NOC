<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add floor_id and office_id to printers
        Schema::table('printers', function (Blueprint $table) {
            $table->unsignedBigInteger('floor_id')->nullable()->after('branch_id')->index();
            $table->unsignedBigInteger('office_id')->nullable()->after('floor_id')->index();

            $table->foreign('floor_id')
                  ->references('id')->on('network_floors')
                  ->nullOnDelete();
            $table->foreign('office_id')
                  ->references('id')->on('network_offices')
                  ->nullOnDelete();
        });

        // Add floor_id and office_id to devices
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedBigInteger('floor_id')->nullable()->after('branch_id')->index();
            $table->unsignedBigInteger('office_id')->nullable()->after('floor_id')->index();

            $table->foreign('floor_id')
                  ->references('id')->on('network_floors')
                  ->nullOnDelete();
            $table->foreign('office_id')
                  ->references('id')->on('network_offices')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropForeign(['floor_id']);
            $table->dropForeign(['office_id']);
            $table->dropColumn(['floor_id', 'office_id']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['floor_id']);
            $table->dropForeign(['office_id']);
            $table->dropColumn(['floor_id', 'office_id']);
        });
    }
};
