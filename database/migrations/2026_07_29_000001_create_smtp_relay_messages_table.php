<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per message relayed through the NOC Postfix smarthost, assembled by
 * the `smtp-relay:ingest-log` command from Postfix maillog lines that share a
 * queue id. Keyed by (queue_id, log_date) because Postfix recycles queue ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_relay_messages', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('queue_id', 24);          // Postfix queue id, e.g. C8B7B42137
            $table->date('log_date');                // date the queue id was seen (disambiguates recycled ids)

            $table->dateTime('queued_at', 3)->nullable();
            $table->string('client_ip', 45)->nullable();   // source device (printer) IP
            $table->string('client_host', 191)->nullable();

            $table->string('mail_from', 320)->nullable();   // envelope sender AFTER rewrite (what SES sees)
            $table->text('recipients')->nullable();         // comma-joined envelope recipients
            $table->unsignedSmallInteger('nrcpt')->default(0);
            $table->text('subject')->nullable();            // from header_checks WARN capture

            $table->unsignedBigInteger('size_bytes')->nullable();       // total Postfix queue size
            $table->unsignedSmallInteger('attachments_count')->default(0);
            $table->unsignedBigInteger('attachments_bytes')->nullable(); // Phase B (milter) — null until then

            // Overall disposition: queued -> sent | bounced | deferred, or rejected
            // (blocked at RCPT, never queued). worst/last recipient status wins.
            $table->string('status', 16)->default('queued');
            $table->string('ses_message_id', 191)->nullable();  // AWS "250 Ok <id>" on accept
            $table->text('ses_response')->nullable();           // full last smtp response text
            $table->text('error')->nullable();                  // reason when bounced/deferred/rejected

            $table->dateTime('last_event_at', 3)->nullable();   // timestamp of the latest log line seen
            $table->timestamps();

            $table->unique(['queue_id', 'log_date']);
            $table->index(['log_date', 'status']);
            $table->index('status');
            $table->index('client_ip');
            $table->index('queued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_relay_messages');
    }
};
