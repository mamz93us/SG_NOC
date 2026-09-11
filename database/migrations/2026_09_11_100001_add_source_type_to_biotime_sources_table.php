<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A source is now one of two ZKTeco tables:
 *  - iclock_transaction (BioTime attendance) — watermark `last_id`;
 *  - acc_transaction (ZKBio access control) — its ids are unordered hex, so
 *    the watermark is (last_time, last_ref). last_time is kept as the
 *    database returned it, fractions included, because the keyset must match
 *    the stored value exactly.
 *
 * stores_utc + timezone: a database that keeps UTC is converted to that zone
 * as punches are read, so attendance_punches stays local wall-clock time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biotime_sources', function (Blueprint $table) {
            $table->string('source_type', 30)->default('iclock_transaction')->after('name');
            $table->string('time_column', 30)->nullable()->after('source_type');
            $table->boolean('stores_utc')->default(false)->after('trust_server_certificate');
            $table->string('timezone', 64)->nullable()->after('stores_utc');
            $table->string('last_time', 40)->nullable()->after('last_id');
            $table->string('last_ref', 64)->nullable()->after('last_time');
        });
    }

    public function down(): void
    {
        Schema::table('biotime_sources', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'time_column', 'stores_utc', 'timezone', 'last_time', 'last_ref']);
        });
    }
};
