<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee KnowBe4 security-awareness figures, synced on a schedule.
 *
 * These are sensitive: a risk score and a phishing-failure count are a
 * statement about one named person's judgement. The home portal shows a row
 * ONLY to the person it belongs to — there is no leaderboard and no
 * cross-employee view, and nothing here should grow one.
 *
 * Matched to employees by email, because that is the only identifier KnowBe4
 * and the NOC reliably share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowbe4_scores', function (Blueprint $table) {
            $table->id();
            // KnowBe4's own user id, so a renamed mailbox does not orphan the row.
            $table->unsignedBigInteger('kb4_user_id')->nullable()->unique();
            $table->string('email', 255)->unique();
            $table->unsignedBigInteger('employee_id')->nullable()->index();

            // KnowBe4 reports risk as 0–100, one decimal.
            $table->decimal('risk_score', 5, 1)->nullable();
            $table->unsignedInteger('phish_fail_count')->default(0);
            $table->unsignedInteger('phish_sent_count')->default(0);
            $table->unsignedInteger('trainings_completed')->default(0);
            $table->unsignedInteger('trainings_outstanding')->default(0);
            $table->string('status', 40)->nullable();
            $table->timestamp('last_phish_failed_at')->nullable();

            $table->timestamp('synced_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowbe4_scores');
    }
};
