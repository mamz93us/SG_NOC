<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Websites the AI Assistant reads into its knowledge base. Each allowed site
 * is crawled on a schedule (`ai:crawl-websites`), and every page becomes an
 * ordinary AiKnowledgeArticle — translated into English when it is not — so
 * the assistant searches it like anything else and cites the page's address.
 *
 * ai_web_sources is what an admin allows: a start URL, the part of the site
 * that may be followed (scope_url), and limits. allow_internal is off by
 * default: the NOC reaches the whole internal network, and a public page must
 * not be able to point the crawler at it.
 *
 * ai_web_pages is the crawl itself, one row per URL found in scope, kept
 * between rounds so an unchanged page (content_hash) costs no translation and
 * a page that disappears (404/410) takes its article with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_web_sources', function (Blueprint $table) {
            $table->id();

            $table->string('name', 200);
            $table->string('start_url', 2048);
            $table->string('scope_url', 2048);
            $table->unsignedSmallInteger('max_pages')->default(50);
            $table->unsignedTinyInteger('max_depth')->default(2);
            $table->unsignedSmallInteger('refresh_days')->default(7); // 0: only when asked
            $table->boolean('allow_internal')->default(false);
            $table->boolean('publish')->default(true);

            // What each page's article is created with — the same shape as ai_knowledge_articles.
            $table->string('category', 50)->nullable();
            $table->string('audience', 20)->default('all');
            $table->unsignedInteger('audience_branch_id')->nullable();
            $table->unsignedBigInteger('audience_department_id')->nullable();

            $table->string('status', 20)->default('queued'); // queued | crawling | idle | failed
            $table->text('error')->nullable();
            $table->unsignedInteger('pages_found')->default(0);
            $table->unsignedInteger('pages_indexed')->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('next_crawl_at')->nullable()->index();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_web_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('ai_web_sources')->cascadeOnDelete();

            $table->string('url', 2048);
            $table->string('url_hash', 64);
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('status', 20)->default('queued'); // queued | indexed | unchanged | skipped | failed | gone
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('title', 255)->nullable();
            $table->string('language', 8)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->unsignedInteger('characters')->default(0);
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'url_hash']);
            $table->index(['source_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_web_pages');
        Schema::dropIfExists('ai_web_sources');
    }
};
