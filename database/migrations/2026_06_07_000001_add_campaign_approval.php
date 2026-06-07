<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IT approval gate for email campaigns.
 *
 * Adds a `pending_approval` status (the per-minute dispatcher only picks up
 * scheduled/sending, so a parked campaign never sends) plus approval metadata,
 * and the two approval-config settings (internal domains + a require-all override).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Widen the status enum. MySQL-only (matches the repo's existing
        // MODIFY COLUMN migrations); on SQLite the column is plain text.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE email_campaigns MODIFY COLUMN status ENUM('draft','pending_approval','scheduled','sending','sent','paused','failed') NOT NULL DEFAULT 'draft'");
        }

        Schema::table('email_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('email_campaigns', 'requires_approval')) {
                $table->boolean('requires_approval')->default(false)->after('status');
            }
            if (! Schema::hasColumn('email_campaigns', 'submitted_for_approval_at')) {
                $table->timestamp('submitted_for_approval_at')->nullable();
            }
            if (! Schema::hasColumn('email_campaigns', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('email_campaigns', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (! Schema::hasColumn('email_campaigns', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('email_campaigns', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (! Schema::hasColumn('email_campaigns', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
        });

        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'email_marketing_internal_domains')) {
                $table->string('email_marketing_internal_domains')->nullable()->default('samirgroup.com,sssegypt.com');
            }
            if (! Schema::hasColumn('settings', 'email_marketing_require_all_approval')) {
                $table->boolean('email_marketing_require_all_approval')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            foreach (['approved_by', 'rejected_by'] as $fk) {
                if (Schema::hasColumn('email_campaigns', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            foreach (['requires_approval', 'submitted_for_approval_at', 'approved_at', 'rejected_at', 'rejection_reason'] as $col) {
                if (Schema::hasColumn('email_campaigns', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('settings', function (Blueprint $table) {
            foreach (['email_marketing_internal_domains', 'email_marketing_require_all_approval'] as $col) {
                if (Schema::hasColumn('settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (DB::getDriverName() === 'mysql') {
            // Revert any parked campaigns first so the narrowed enum accepts every row.
            DB::table('email_campaigns')->where('status', 'pending_approval')->update(['status' => 'draft']);
            DB::statement("ALTER TABLE email_campaigns MODIFY COLUMN status ENUM('draft','scheduled','sending','sent','paused','failed') NOT NULL DEFAULT 'draft'");
        }
    }
};
