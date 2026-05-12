<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avepoint_backups', function (Blueprint $table) {
            $table->id();

            // Subject (whose data is being backed up)
            $table->string('subject_upn', 200)->index();
            $table->string('subject_name', 200)->nullable();
            $table->unsignedBigInteger('subject_identity_user_id')->nullable();
            $table->unsignedBigInteger('subject_employee_id')->nullable();

            // Request metadata
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->text('notes')->nullable();

            // Backup details
            $table->enum('type', ['mailbox', 'onedrive']);
            $table->enum('source', ['avepoint', 'manual_upload'])->default('avepoint');
            $table->string('avepoint_job_id', 100)->nullable();
            $table->string('status', 32)->default('pending');
            //   pending → running → uploading → completed
            //   manual_upload_required → completed
            //   failed / pruned

            // File on Azure Blob
            $table->string('file_path', 500)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_sha256', 64)->nullable();

            // Download link
            $table->string('download_token', 64)->nullable()->unique();
            $table->timestamp('download_expires_at')->nullable();
            $table->timestamp('requester_notified_at')->nullable();

            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('subject_identity_user_id')->references('id')->on('identity_users')->onDelete('set null');
            $table->foreign('subject_employee_id')->references('id')->on('employees')->onDelete('set null');
            $table->foreign('requested_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['subject_upn', 'type']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('avepoint_download_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('avepoint_backup_id');
            $table->string('download_token', 64);
            $table->unsignedBigInteger('user_id')->nullable()->comment('NOC user — null for token-only downloads');
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedBigInteger('bytes_sent')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            $table->foreign('avepoint_backup_id')->references('id')->on('avepoint_backups')->onDelete('cascade');
            $table->index('download_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avepoint_download_audits');
        Schema::dropIfExists('avepoint_backups');
    }
};
