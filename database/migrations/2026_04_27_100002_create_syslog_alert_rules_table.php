<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pattern-based rules that turn syslog rows into NocEvents.
 *
 * MatchSyslogAlertsJob runs every minute, pulls unprocessed
 * syslog_messages rows, and for each enabled rule whose filters match
 * (severity threshold, source_type, host pattern, message pattern),
 * creates or refreshes a NocEvent so the existing notification routing
 * picks it up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syslog_alert_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->boolean('enabled')->default(true);

            // Filters — all that are non-null must match.
            // Severity threshold: rule fires when message severity <= this
            // value (lower number = more severe in syslog).
            $table->unsignedTinyInteger('severity_max')->default(4);

            // 'sophos' | 'cisco' | 'ucm' | 'printer' | 'vps' | null=any
            $table->string('source_type', 32)->nullable();

            // Optional host substring filter (LIKE %...% on host column).
            $table->string('host_contains', 191)->nullable();

            // Optional message regex (PHP PCRE, anchored by user). When
            // null, every message that passes the other filters fires.
            $table->string('message_regex', 500)->nullable();

            // What to do when the rule fires.
            // Severity to give the resulting NocEvent.
            $table->string('event_severity', 16)->default('warning');
            // Module to attach (existing NocEvent.module — 'syslog' is fine).
            $table->string('event_module', 32)->default('syslog');

            // Cooldown between identical events (prevents alert storms).
            $table->unsignedSmallInteger('cooldown_minutes')->default(15);

            // Bookkeeping.
            $table->dateTime('last_matched_at')->nullable();
            $table->unsignedInteger('match_count')->default(0);

            $table->timestamps();

            $table->index(['enabled', 'severity_max']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syslog_alert_rules');
    }
};
