<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery audit for the WhatsApp channel, mirroring email_logs.
 *
 * `wamid` is Meta's message id — the only handle for chasing a message that
 * was accepted by the API but never arrived on the handset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to_number', 32);
            $table->string('to_name', 150)->nullable();
            $table->string('notification_type', 100)->nullable();
            $table->unsignedBigInteger('notification_id')->nullable();
            $table->string('channel_mode', 16)->default('template'); // template | text
            $table->string('template_name', 128)->nullable();
            $table->text('body')->nullable();
            $table->string('status', 16)->default('sent');           // sent | failed
            $table->string('wamid', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->index('notification_type');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_logs');
    }
};
