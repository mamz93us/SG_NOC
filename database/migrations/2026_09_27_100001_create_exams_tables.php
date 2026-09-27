<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Practice exams for the team (AZ-900, AI-900, …), taken under exam
 * conditions: a random draw from the bank, a server-side clock, a 1–1000
 * score with 700 to pass.
 *
 * An attempt keeps its own question order, option order, answers and — once
 * graded — which questions it got right, so a question edited or deleted
 * later never changes a score already given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('duration_minutes')->default(45);
            // Questions drawn per attempt; 0 = the whole active bank.
            $t->unsignedSmallInteger('question_count')->default(45);
            $t->unsignedSmallInteger('passing_score')->default(700);
            // Whether a candidate may see the correct answers after finishing.
            $t->boolean('show_review')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('exam_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            // Stable key from the bank file, so reloading a bank updates rather
            // than duplicates. Null for questions typed in on the page.
            $t->string('uid', 64)->nullable();
            $t->string('domain', 200);
            $t->string('type', 20)->default('single'); // single | multiple
            $t->text('question');
            $t->json('options');   // {"A": "...", "B": "..."}
            $t->json('answer');    // ["B"] or ["A","C"]
            $t->text('explanation')->nullable();
            $t->string('reference', 500)->nullable();
            $t->boolean('shuffle_options')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['exam_id', 'uid']);
            $t->index(['exam_id', 'is_active']);
        });

        Schema::create('exam_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('status', 20)->default('in_progress'); // in_progress | submitted | expired
            $t->dateTime('started_at');
            $t->dateTime('expires_at');
            $t->dateTime('submitted_at')->nullable();
            $t->json('question_ids');            // [12, 7, 40, …] in delivery order
            $t->json('option_orders')->nullable(); // {"12": ["C","A","B","D"]}
            $t->json('answers')->nullable();       // {"12": ["A"]}
            $t->json('flagged')->nullable();       // [12, 40]
            $t->unsignedSmallInteger('total_questions')->default(0);
            $t->unsignedSmallInteger('correct_count')->nullable();
            $t->unsignedSmallInteger('score')->nullable(); // 0–1000
            $t->unsignedSmallInteger('passing_score')->default(700);
            $t->boolean('passed')->nullable();
            $t->json('results')->nullable();        // {"12": true, "7": false}
            $t->json('domain_results')->nullable(); // [{"domain":…, "correct":…, "total":…}]
            $t->timestamps();

            $t->index(['user_id', 'status']);
            $t->index(['exam_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('exams');
    }
};
