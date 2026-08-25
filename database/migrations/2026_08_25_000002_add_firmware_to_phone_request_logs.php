<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grandstream phones announce their running firmware in the User-Agent they send
 * when they fetch /phonebook.xml:
 *
 *   Grandstream Model HW GRP2616 SW 1.0.13.59 DevId ec74d7800474
 *
 * PhonebookController already parsed the model and MAC out of that string and
 * threw the SW away. Keeping it gives a self-reported, always-current firmware
 * inventory for the whole fleet with no GDMS round trip — which is what the
 * firmware status board reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_request_logs', function (Blueprint $table) {
            $table->string('firmware', 40)->nullable()->after('model');
            $table->index(['mac', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('phone_request_logs', function (Blueprint $table) {
            $table->dropIndex(['mac', 'created_at']);
            $table->dropColumn('firmware');
        });
    }
};
