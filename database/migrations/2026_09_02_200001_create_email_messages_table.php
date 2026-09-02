<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per message SES told us about — the account-wide sent-mail log.
 *
 * `email_events` already receives every SES notification (marketing campaigns
 * AND transactional NOC mail), but it only stores the event, with the sender,
 * recipient and subject buried inside raw_payload JSON. That makes "did we
 * email this person, and did it arrive" unanswerable without scanning JSON.
 *
 * This table folds those events into the message they describe: identity and
 * subject up front, current delivery state, and the timestamps of each stage.
 * The events themselves stay where they are and remain the source of truth —
 * this is a queryable projection of them, joined on ses_message_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->string('ses_message_id', 255)->unique();

            $table->string('from_email', 320)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('to_email', 320)->nullable()->comment('First recipient; recipient_count says if there were more');
            $table->text('recipients')->nullable()->comment('All destinations, comma separated');
            $table->unsignedSmallInteger('recipient_count')->default(1);
            $table->text('subject')->nullable();

            $table->string('source', 20)->default('transactional')->comment('marketing | transactional');
            $table->foreignId('email_campaign_send_id')->nullable()
                ->constrained('email_campaign_sends')->nullOnDelete();

            $table->string('status', 20)->default('sent')
                ->comment('sent | delivered | bounced | complained | rejected | failed');
            $table->string('bounce_type', 50)->nullable();
            $table->string('bounce_subtype', 50)->nullable();
            $table->string('complaint_type', 50)->nullable();
            $table->text('failure_reason')->nullable();

            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();

            $table->index('sent_at');
            $table->index(['status', 'sent_at']);
            $table->index(['source', 'sent_at']);
            $table->index('from_email');
            $table->index('to_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
