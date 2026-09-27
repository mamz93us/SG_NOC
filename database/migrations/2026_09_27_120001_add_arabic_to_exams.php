<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arabic for the practice exams. A question carries its Arabic beside the
 * English, option for option under the same keys, so grading never looks at
 * the language; an attempt remembers the language the candidate chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $t) {
            $t->string('title_ar', 200)->nullable()->after('title');
            $t->text('description_ar')->nullable()->after('description');
        });

        Schema::table('exam_questions', function (Blueprint $t) {
            $t->text('question_ar')->nullable()->after('question');
            $t->json('options_ar')->nullable()->after('options'); // same keys as options
            $t->text('explanation_ar')->nullable()->after('explanation');
        });

        Schema::table('exam_attempts', function (Blueprint $t) {
            $t->string('language', 5)->default('en')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', fn (Blueprint $t) => $t->dropColumn('language'));
        Schema::table('exam_questions', fn (Blueprint $t) => $t->dropColumn(['question_ar', 'options_ar', 'explanation_ar']));
        Schema::table('exams', fn (Blueprint $t) => $t->dropColumn(['title_ar', 'description_ar']));
    }
};
