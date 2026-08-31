<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings for the two home-portal integrations.
 *
 * Both credentials live here rather than in .env, like every other credential
 * in this app: they can be rotated from the UI without a deploy, and the
 * encrypted-at-rest mutators on App\Models\Setting apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // ── KnowBe4 (Security Score card) ─────────────────────────
            $table->boolean('knowbe4_enabled')->default(false)->after('noc_ticket_catalog');
            $table->text('knowbe4_api_token')->nullable()->after('knowbe4_enabled');
            // KnowBe4's API host is region-specific — us / eu / ca / uk / de.
            // A token issued in one region returns 401 against another, which
            // looks exactly like a bad token, so this must be explicit.
            $table->string('knowbe4_region', 5)->default('us')->after('knowbe4_api_token');
            $table->timestamp('knowbe4_last_sync_at')->nullable()->after('knowbe4_region');

            // ── Company calendar (Microsoft Graph) ────────────────────
            $table->boolean('company_calendar_enabled')->default(false)->after('knowbe4_last_sync_at');
            // The shared mailbox or group whose calendar is the company
            // calendar, e.g. "calendar@samirgroup.com".
            $table->string('company_calendar_mailbox', 255)->nullable()->after('company_calendar_enabled');
            $table->timestamp('company_calendar_last_sync_at')->nullable()->after('company_calendar_mailbox');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'knowbe4_enabled',
                'knowbe4_api_token',
                'knowbe4_region',
                'knowbe4_last_sync_at',
                'company_calendar_enabled',
                'company_calendar_mailbox',
                'company_calendar_last_sync_at',
            ]);
        });
    }
};
