<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receiver: rsyslog (system service) writes rows here via ommysql.
 * Writer:   the rsyslog SQL template inserts the timestamp/severity/host/
 *           message columns directly. The `source_type`, `source_id`, and
 *           `processed_at` columns are filled in later by Laravel jobs
 *           (TagSyslogSourcesJob / MatchSyslogAlertsJob).
 *
 * The schema is intentionally narrow and indexed for the common NOC
 * filters (time + host + severity + program). 30-day retention is enforced
 * by PruneOldSyslogJob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syslog_messages', function (Blueprint $table) {
            $table->bigIncrements('id');

            // When rsyslog received the packet (UTC, ms precision).
            $table->dateTime('received_at', 3);

            // The timestamp the device put on the message (may be null/skewed).
            $table->dateTime('device_time')->nullable();

            // Standard syslog facility (0-23) and severity (0-7, 0=emerg).
            $table->unsignedTinyInteger('facility')->nullable();
            $table->unsignedTinyInteger('severity');

            // Hostname as reported by the device (string the device sent).
            $table->string('host', 191);

            // Source IP rsyslog observed the packet from (after any DNAT).
            $table->string('source_ip', 45);

            // Free-form syslog tag / program name (e.g. "sshd", "kernel",
            // "device.SecurityPolicy"). Kept short for index efficiency.
            $table->string('program', 128)->nullable();

            // Parsed message body. TEXT (rsyslog can produce long lines).
            $table->text('message');

            // The full raw RFC3164/5424 packet as received. Useful for
            // debugging parsing problems and for re-running the alert
            // matcher with a different rule.
            $table->text('raw')->nullable();

            // Filled in by TagSyslogSourcesJob: which kind of device sent
            // this — sophos|cisco|ucm|printer|vps|unknown.
            $table->string('source_type', 32)->nullable();

            // Filled in by TagSyslogSourcesJob: PK of the matching record
            // in sophos_firewalls / network_switches / ucm_servers /
            // printers / monitored_hosts. NULL when source_type='vps' or
            // 'unknown'.
            $table->unsignedBigInteger('source_id')->nullable();

            // When the alert matcher last looked at this row. NULL means
            // the matcher hasn't run yet for this row.
            $table->dateTime('processed_at')->nullable();

            // Indexes — list/filter UI hits these constantly.
            $table->index('received_at');
            $table->index(['host', 'received_at']);
            $table->index(['severity', 'received_at']);
            $table->index(['source_type', 'received_at']);
            $table->index(['source_ip', 'received_at']);
            $table->index('program');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syslog_messages');
    }
};
