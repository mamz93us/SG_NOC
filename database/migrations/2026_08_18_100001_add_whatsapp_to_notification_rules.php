<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp as a third delivery channel on a routing rule.
 *
 * `whatsapp_numbers` carries on-call / group numbers that do not belong to a
 * NOC user account — an out-of-hours phone, a manager who never signs in.
 * Without it the channel would only reach people who both have a login and
 * have filled in their number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_rules', function (Blueprint $table) {
            $table->boolean('send_whatsapp')->default(false)->after('send_in_app');
            $table->text('whatsapp_numbers')->nullable()->after('send_whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('notification_rules', function (Blueprint $table) {
            $table->dropColumn(['send_whatsapp', 'whatsapp_numbers']);
        });
    }
};
