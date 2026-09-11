<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * external_id: the source row's own id as text, unique per source — BioTime's
 * number, or an access-control table's 32-character hex id. Punches are
 * upserted on (source, external_id). biotime_id stays for BioTime rows and is
 * null for access-control ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->string('external_id', 64)->nullable()->after('biotime_id');
        });

        DB::table('attendance_punches')->update(['external_id' => DB::raw('CAST(biotime_id AS CHAR)')]);

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->unsignedBigInteger('biotime_id')->nullable()->change();
            $table->unique(['biotime_source_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropUnique(['biotime_source_id', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
