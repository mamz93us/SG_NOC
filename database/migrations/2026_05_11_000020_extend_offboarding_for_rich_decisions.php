<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Token: allow longer lifetime for manager grace + reminders ─────────
        // (The default-30-days expiry is set in OffboardingToken::generate;
        //  no schema change needed here. Recorded for the audit trail.)

        // ── New table: offboarding_workflows (one row per offboarding) ─────────
        Schema::create('offboarding_workflows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('employee_id')->nullable();

            $table->string('status', 32)->default('manager_input_pending');
            //   manager_input_pending → processing → active → escalated → retention → completed
            //   plus failed / cancelled

            // Manager decisions (filled in when manager submits)
            $table->enum('email_action', ['delete', 'forward'])->nullable();
            $table->json('forward_emails')->nullable();
            $table->date('forward_until')->nullable();
            $table->enum('laptop_action', ['backup', 'delete'])->nullable();
            $table->enum('asset_action', ['transfer', 'return_to_it'])->nullable();
            $table->unsignedBigInteger('asset_target_employee_id')->nullable();
            $table->json('retrieval_choices')->nullable(); // {asset_id: bool}

            // Lifecycle
            $table->date('expected_last_day');
            $table->timestamp('azure_disabled_at')->nullable();
            $table->timestamp('azure_deleted_at')->nullable();
            $table->date('delete_after')->nullable();
            $table->date('manager_grace_until')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Bookkeeping
            $table->string('forward_rule_id', 100)->nullable()->comment('Graph inbox rule id');
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('workflow_requests')->onDelete('cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('set null');
            $table->foreign('asset_target_employee_id')->references('id')->on('employees')->onDelete('set null');
            $table->index(['status', 'expected_last_day']);
            $table->index('delete_after');
        });

        // ── New table: offboarding_backups (mailbox + onedrive + laptop) ───────
        Schema::create('offboarding_backups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('offboarding_workflow_id');

            $table->enum('type', ['mailbox', 'onedrive', 'laptop']);
            $table->enum('source', ['avepoint', 'manual_upload'])->default('avepoint');
            $table->string('avepoint_job_id', 100)->nullable();

            $table->string('status', 32)->default('pending');
            //   pending → running → uploading → completed
            //   manual_upload_required → completed
            //   failed / pruned

            $table->string('file_path', 500)->nullable()->comment('Azure Blob key');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_sha256', 64)->nullable();

            $table->string('download_token', 64)->nullable()->unique();
            $table->timestamp('download_expires_at')->nullable();
            $table->timestamp('manager_notified_at')->nullable();

            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('offboarding_workflow_id')
                ->references('id')->on('offboarding_workflows')
                ->onDelete('cascade');
            $table->index(['offboarding_workflow_id', 'type']);
            $table->index('status');
        });

        // ── New table: offboarding_download_audits (per-download log) ──────────
        Schema::create('offboarding_download_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('offboarding_backup_id');
            $table->string('download_token', 64);
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedBigInteger('bytes_sent')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            $table->foreign('offboarding_backup_id')
                ->references('id')->on('offboarding_backups')
                ->onDelete('cascade');
            $table->index('download_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboarding_download_audits');
        Schema::dropIfExists('offboarding_backups');
        Schema::dropIfExists('offboarding_workflows');
    }
};
