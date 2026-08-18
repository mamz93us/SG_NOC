<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user opt-out for the WhatsApp channel, mirroring notify_email.
 *
 * The rule decides who is paged; this can only veto. Defaults to true so an
 * existing rule switched to WhatsApp reaches its recipients without every user
 * first having to opt in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->boolean('notify_whatsapp')->default(true)->after('notify_in_app');
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn('notify_whatsapp');
        });
    }
};
