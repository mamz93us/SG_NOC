<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets transactional mail (workflows, onboarding, offboarding, notifications,
 * printer alerts) go out through the SAME AWS SES account the marketing portal
 * already uses, instead of the separate SMTP host in the smtp_* columns.
 *
 * The SES credentials are NOT duplicated — the ses_* columns added by
 * 2026_05_17_000001_add_email_marketing_to_settings are reused as-is. This only
 * adds the switch that says which transport transactional mail should use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('mail_transport', 16)->default('smtp')->after('smtp_from_name');
        });

        // Installs that already have SES credentials configured (i.e. the
        // marketing portal is set up) are flipped to SES, which is the point of
        // this change. Anything without SES stays on SMTP so a half-configured
        // install does not silently stop sending mail.
        DB::table('settings')
            ->whereNotNull('ses_region')
            ->whereNotNull('ses_access_key_id')
            ->whereNotNull('ses_secret_access_key')
            ->update(['mail_transport' => 'ses']);
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('mail_transport');
        });
    }
};
