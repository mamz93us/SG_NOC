<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_subscriber_tag', function (Blueprint $table) {
            $table->foreignId('email_subscriber_id')->constrained('email_subscribers')->cascadeOnDelete();
            $table->foreignId('email_tag_id')->constrained('email_tags')->cascadeOnDelete();
            $table->primary(['email_subscriber_id', 'email_tag_id'], 'email_subscriber_tag_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_subscriber_tag');
    }
};
