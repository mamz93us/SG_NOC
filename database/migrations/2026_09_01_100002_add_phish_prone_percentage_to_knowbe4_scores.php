<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phish-Prone Percentage — KnowBe4's headline per-person figure, and the one
 * the portal's Security Score card now leads with instead of the risk score.
 *
 * Comes straight from `/v1/users.phish_prone_percentage` on a **0–100 scale**
 * (verified against the live tenant: min 0, max 100, mean 21). It is the share
 * of simulated phishing emails that person failed, so it cross-checks against
 * the counts aggregated by `knowbe4:sync` — a user at 100 has failed every
 * test delivered to them.
 *
 * Nullable: a person KnowBe4 has never phished has no percentage, which is not
 * the same as zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowbe4_scores', function (Blueprint $table) {
            $table->decimal('phish_prone_percentage', 5, 2)->nullable()->after('risk_score');
        });
    }

    public function down(): void
    {
        Schema::table('knowbe4_scores', function (Blueprint $table) {
            $table->dropColumn('phish_prone_percentage');
        });
    }
};
