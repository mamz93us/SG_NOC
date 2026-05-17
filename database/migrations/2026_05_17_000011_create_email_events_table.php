<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_events', function (Blueprint $table) {
            $table->id();
            $table->string('ses_message_id', 255)->nullable();
            $table->foreignId('email_campaign_send_id')->nullable()->constrained('email_campaign_sends')->nullOnDelete();
            $table->foreignId('email_subscriber_id')->nullable()->constrained('email_subscribers')->nullOnDelete();
            $table->enum('event_type', [
                'Send', 'Delivery', 'Open', 'Click', 'Bounce',
                'Complaint', 'Reject', 'RenderingFailure',
            ]);
            $table->text('url')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('bounce_type', 50)->nullable();
            $table->string('bounce_subtype', 50)->nullable();
            $table->string('complaint_type', 50)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('ses_message_id');
            $table->index('email_campaign_send_id');
            $table->index('email_subscriber_id');
            $table->index(['event_type', 'created_at']);
            $table->index(['email_campaign_send_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_events');
    }
};
