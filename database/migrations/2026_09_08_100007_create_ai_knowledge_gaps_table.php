<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every search_knowledge query that came back empty, with a hit counter.
 *
 * This is the backlog of articles IT should write next — the feature that
 * keeps the assistant improving instead of plateauing at whatever was seeded
 * on day one. Deduplicated on the normalized query text so the same recurring
 * question increments one row rather than piling up duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_gaps', function (Blueprint $table) {
            $table->id();

            $table->string('query_normalized', 500)->unique();
            $table->text('query_sample');
            $table->unsignedInteger('hit_count')->default(1);
            $table->string('locale', 5)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_gaps');
    }
};
