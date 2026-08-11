<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a probe got the verdict it did, in a few words.
 *
 * ICMP and TCP are binary enough that "down" says everything. UDP is not: a red
 * RTP probe reading "no reply" (nothing crossed the tunnel) and one reading
 * "port closed" (the datagram arrived) are opposite diagnoses, and the operator
 * looking at the board needs to tell them apart without opening the alert email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tunnel_probes', function (Blueprint $table) {
            $table->string('result_note', 120)->nullable()->after('latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('tunnel_probes', function (Blueprint $table) {
            $table->dropColumn('result_note');
        });
    }
};
