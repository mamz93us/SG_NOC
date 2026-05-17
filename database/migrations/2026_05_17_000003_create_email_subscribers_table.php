<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->enum('status', [
                'pending', 'subscribed', 'unsubscribed', 'bounced', 'complained',
            ])->default('pending');
            $table->string('source', 50)->nullable();
            $table->json('attributes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->string('last_bounce_type', 50)->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_subscribers');
    }
};
