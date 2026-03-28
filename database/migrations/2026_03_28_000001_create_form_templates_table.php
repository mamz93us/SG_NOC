<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->enum('type', ['feedback', 'survey', 'request', 'intake'])->default('feedback');
            $table->enum('visibility', ['public', 'private', 'token_only'])->default('private');
            $table->json('schema');               // array of field definitions
            $table->json('settings');             // confirmation_message, notify_user_ids, etc.
            $table->foreignId('workflow_template_id')->nullable()->constrained()->nullOnDelete();
            $table->json('workflow_payload_map')->nullable();  // field_name → payload_key
            $table->foreignId('created_by')->constrained('users');
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_templates');
    }
};
