<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price the AI conversations by what they actually used.
 *
 * `page_read_cost_usd` prices reading, which is genuinely per-page, and that part
 * was right. The conversations were not: asking the archive a question runs a
 * tool-calling loop of up to six turns, and asking about a document sends up to
 * 40,000 characters of page text — both were recorded as a flat half of one
 * page's cost, whatever they really used.
 *
 * That understates the spend, and it understates it silently, which is the worst
 * property a budget can have: the cap is checked against recorded usage, so a
 * figure that reads low lets spending continue past the limit somebody set.
 *
 * Azure returns prompt_tokens and completion_tokens on every call already
 * (AzureOpenAiClient::chat), so the fix is a price to multiply them by. Keyed in
 * rather than fetched, for the same reason page_read_cost_usd and the exchange
 * rates are: an estimate shown before somebody commits has to still mean the same
 * thing when they come back to check it.
 *
 * Defaults are gpt-4o's list prices as of 2026-09 ($2.50 / $10.00 per million
 * tokens). They are a starting point to be corrected from a real invoice, not a
 * claim about what this tenancy is charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archive_ai_settings', function (Blueprint $table) {
            // Per 1,000 tokens, which is the unit people read prices in, at five
            // decimal places — $2.50 per million is 0.00250 per thousand.
            $table->decimal('prompt_token_cost_usd', 8, 5)->default(0.0025)->after('page_read_cost_usd');
            $table->decimal('completion_token_cost_usd', 8, 5)->default(0.01)->after('prompt_token_cost_usd');
        });
    }

    public function down(): void
    {
        Schema::table('archive_ai_settings', function (Blueprint $table) {
            $table->dropColumn(['prompt_token_cost_usd', 'completion_token_cost_usd']);
        });
    }
};
