<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access-control punches carry no area, so a terminal is mapped to a branch
 * and time zone directly. For BioTime punches the area still wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biotime_terminals', function (Blueprint $table) {
            $table->unsignedInteger('branch_id')->nullable()->after('area_alias')->index();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->string('timezone', 64)->nullable()->after('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('biotime_terminals', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['branch_id', 'timezone']);
        });
    }
};
