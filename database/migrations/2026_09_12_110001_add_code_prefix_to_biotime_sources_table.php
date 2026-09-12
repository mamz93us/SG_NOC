<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three settings the legacy ZKTeco (CHECKINOUT + USERINFO) sources need.
 *
 * `code_prefix` is prepended to the device code before it is looked up as an
 * Oracle EMP_NO — Cairo's badge 512 is Oracle 55512. It is a matching rule
 * only: the raw badge is what stays in attendance_punches, so changing the
 * prefix costs a re-match, never a re-read.
 *
 * `code_column` says which of USERINFO's three identity columns carries that
 * code. `lookback_days` re-reads the last few days on every sync: CHECKINOUT
 * has no write-time column, so a terminal uploading late writes rows BEHIND a
 * (time, id) watermark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biotime_sources', function (Blueprint $table) {
            $table->string('code_prefix', 10)->nullable()->after('time_column');
            $table->string('code_column', 30)->nullable()->after('code_prefix');
            $table->unsignedTinyInteger('lookback_days')->default(0)->after('import_from');
        });
    }

    public function down(): void
    {
        Schema::table('biotime_sources', function (Blueprint $table) {
            $table->dropColumn(['code_prefix', 'code_column', 'lookback_days']);
        });
    }
};
