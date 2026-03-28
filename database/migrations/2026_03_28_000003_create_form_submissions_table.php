<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('form_templates')->cascadeOnDelete();
            $table->foreignId('token_id')->nullable()->constrained('form_tokens')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submitter_email', 150)->nullable();
            $table->string('ip_address', 45);
            $table->json('data');                 // field_name → value pairs
            $table->enum('status', ['new', 'reviewed', 'actioned', 'closed'])->default('new');
            $table->text('reviewer_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('workflow_request_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('form_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
