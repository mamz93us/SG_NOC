<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment AI: the jobs a recruiter switched AI screening on for, and one
 * screening per applicant — the CV text read once, and the AI's evaluation of
 * it against the job ad and the recruiter's must-haves.
 *
 * CV text and application answers are encrypted at rest (see the model casts).
 * Kept until someone deletes a job's AI data from its page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('teamtailor_job_id', 32)->unique();
            $table->string('title')->nullable();
            $table->string('job_status', 30)->nullable();
            $table->text('must_haves')->nullable();
            $table->boolean('screening_enabled')->default(false);
            $table->timestamp('screening_enabled_at')->nullable();
            $table->unsignedBigInteger('screening_enabled_by')->nullable();
            $table->timestamp('criteria_updated_at')->nullable();
            $table->unsignedBigInteger('criteria_updated_by')->nullable();
            $table->string('criteria_hash', 64)->nullable();
            $table->unsignedInteger('applicant_count')->default(0);
            $table->timestamp('applicants_synced_at')->nullable();
            $table->timestamp('last_screened_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('recruitment_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_job_id')->constrained('recruitment_jobs')->cascadeOnDelete();
            $table->string('teamtailor_candidate_id', 32);
            $table->string('teamtailor_application_id', 32)->nullable();
            $table->string('candidate_name', 200)->nullable();
            $table->string('candidate_email', 200)->nullable();
            $table->string('candidate_location', 200)->nullable();
            $table->string('linkedin_url', 500)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('stage', 100)->nullable();
            $table->boolean('rejected')->default(false);
            $table->string('resume_updated_at', 40)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->longText('cv_text')->nullable();
            $table->unsignedSmallInteger('cv_pages')->nullable();
            $table->string('cv_read_as', 10)->nullable();
            $table->longText('answers_text')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('fit', 20)->nullable();
            $table->unsignedTinyInteger('must_haves_met')->nullable();
            $table->unsignedTinyInteger('must_haves_total')->nullable();
            $table->json('evaluation')->nullable();
            $table->string('criteria_hash', 64)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->timestamp('screened_at')->nullable();
            $table->timestamps();

            $table->unique(['recruitment_job_id', 'teamtailor_candidate_id'], 'recruitment_screenings_job_candidate_unique');
            $table->index(['recruitment_job_id', 'status']);
            $table->index(['recruitment_job_id', 'score']);
        });

        // A transcript that used a recruitment tool carries candidate data, so
        // AI ▸ Conversations shows it only to people who may use Recruitment AI.
        // The job an Ask conversation on the Recruitment AI page was about, so
        // deleting that job's AI data deletes the conversation too.
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->boolean('contains_candidate_data')->default(false)->after('locale');
            $table->unsignedBigInteger('recruitment_job_id')->nullable()->after('contains_candidate_data')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropIndex(['recruitment_job_id']);
            $table->dropColumn(['contains_candidate_data', 'recruitment_job_id']);
        });

        Schema::dropIfExists('recruitment_screenings');
        Schema::dropIfExists('recruitment_jobs');
    }
};
