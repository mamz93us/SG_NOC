<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->foreignId('email_subscriber_id')->constrained('email_subscribers')->cascadeOnDelete();
            $table->string('ses_message_id', 255)->nullable();
            $table->enum('status', [
                'queued', 'sent', 'delivered', 'bounced', 'complained',
                'failed', 'suppressed',
            ])->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['email_campaign_id', 'email_subscriber_id'], 'email_campaign_sends_unique');
            $table->index('ses_message_id');
            $table->index(['email_campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_sends');
    }
};
