<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One turn in an ai_conversations thread — user text, an assistant reply, or a
 * tool result feeding back into the loop.
 *
 * `tool_call_id` links a `role=tool` row back to the assistant message's
 * `tool_calls` entry that requested it, which OpenAI-shaped chat APIs require
 * to stitch a function result back into context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();

            $table->string('role', 20); // user | assistant | tool | system
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id', 64)->nullable();

            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            $table->tinyInteger('rating')->nullable(); // -1 | 0 | 1
            $table->string('rating_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
