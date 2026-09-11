<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pay period: the date range, for one branch or all of them, that goes to
 * Oracle as one approved unit.
 *
 * status: open → approved (its days are locked, the export is prepared) →
 * sent (the Oracle sender accepted it). Reopening sets it back to open, with
 * who, when and why kept here.
 *
 * The branch foreign key restricts deletes on purpose: nulling it would turn
 * a one-branch period into an all-branches one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->unsignedInteger('branch_id')->nullable()->index();
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->index(['date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_periods');
    }
};
