<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Config for the home portal's AI IT Assistant (Azure OpenAI).
 *
 * Its own single-row table rather than columns on `settings` — that table is
 * already at InnoDB's row-size limit (see the Samsung Wallet and exchange
 * rates migrations) and this needs ~a dozen fields. Same `::get()` singleton
 * pattern as Setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();

            $table->boolean('enabled')->default(false);

            // Azure OpenAI — company tenancy, so HR/policy content never
            // leaves it for a third-party API.
            $table->string('azure_endpoint', 500)->nullable();
            $table->text('azure_api_key')->nullable(); // encrypted at rest
            $table->string('azure_api_version', 32)->default('2024-08-01-preview');
            $table->string('chat_deployment', 128)->nullable();
            $table->string('embedding_deployment', 128)->nullable();

            $table->unsignedInteger('max_output_tokens')->default(800);
            $table->decimal('temperature', 3, 2)->default(0.20);
            $table->unsignedTinyInteger('max_tool_turns')->default(6);

            $table->unsignedInteger('daily_message_cap')->default(60);
            $table->unsignedInteger('retention_days')->default(180);

            // Appended to the system prompt in lang/*/home_ai.php, so an admin
            // can adjust tone/scope without a deploy.
            $table->text('system_prompt_extra')->nullable();

            $table->boolean('ticket_drafting_enabled')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
