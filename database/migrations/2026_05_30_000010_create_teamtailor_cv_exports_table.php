<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per "download all CVs for this job" request. The actual work
        // (paging the ATS, fetching each résumé, zipping, uploading to Azure
        // Blob) runs out-of-band in the teamtailor:process-cv-exports scheduled
        // command — production has no queue worker, only the scheduler.
        Schema::create('teamtailor_cv_exports', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->index();              // Teamtailor job id
            $table->string('job_title')->nullable();
            $table->string('status')->default('pending')->index(); // pending|processing|completed|failed
            $table->unsignedInteger('total_candidates')->default(0);
            $table->unsignedInteger('cv_count')->default(0);   // résumés actually zipped
            $table->unsignedInteger('failed_count')->default(0); // résumés that 404'd / errored
            $table->string('disk')->nullable();                // azure disk the zip lives on
            $table->string('file_path')->nullable();           // blob key of the finished zip
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable()->index(); // users.id
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teamtailor_cv_exports');
    }
};
