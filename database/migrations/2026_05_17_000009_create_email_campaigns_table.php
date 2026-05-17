<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject', 255);
            $table->string('preview_text', 255)->nullable();
            $table->string('from_email', 191);
            $table->string('from_name', 191);
            $table->string('reply_to', 191)->nullable();
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->foreignId('email_list_id')->nullable()->constrained('email_lists')->nullOnDelete();
            $table->foreignId('email_segment_id')->nullable()->constrained('email_segments')->nullOnDelete();
            $table->enum('status', [
                'draft', 'scheduled', 'sending', 'sent', 'paused', 'failed',
            ])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('total_sent')->default(0);
            $table->unsignedInteger('total_delivered')->default(0);
            $table->unsignedInteger('total_opens')->default(0);
            $table->unsignedInteger('total_unique_opens')->default(0);
            $table->unsignedInteger('total_clicks')->default(0);
            $table->unsignedInteger('total_unique_clicks')->default(0);
            $table->unsignedInteger('total_bounces')->default(0);
            $table->unsignedInteger('total_complaints')->default(0);
            $table->unsignedInteger('total_unsubscribes')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('email_list_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaigns');
    }
};
