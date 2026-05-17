<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_list_subscriber', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_list_id')->constrained('email_lists')->cascadeOnDelete();
            $table->foreignId('email_subscriber_id')->constrained('email_subscribers')->cascadeOnDelete();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('opt_in_token', 64)->nullable()->unique();
            $table->timestamp('opt_in_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['email_list_id', 'email_subscriber_id'], 'email_list_subscriber_unique');
            $table->index('email_subscriber_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_list_subscriber');
    }
};
