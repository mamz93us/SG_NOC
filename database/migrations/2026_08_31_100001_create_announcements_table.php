<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company announcements shown on the employee home portal.
 *
 * Two tables: the announcements themselves, and per-user read state that drives
 * the unread count and the pulsing bell on the Announcements card.
 *
 * Audience is deliberately coarse — everyone, one branch, or one department.
 * Anything finer would need a rules engine, and the thing being modelled is a
 * noticeboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            $table->string('title', 200);
            $table->string('title_ar', 200)->nullable();
            $table->text('body');
            $table->text('body_ar')->nullable();

            // Optional click-through, e.g. a policy PDF or an intranet page.
            $table->string('link_url', 500)->nullable();
            $table->string('link_label', 80)->nullable();

            // urgent slides get the red treatment in the slider.
            $table->string('severity', 20)->default('info'); // info | success | urgent
            $table->boolean('pinned')->default(false);

            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            // Null = never expires. The slider filters on this, so a stale
            // notice drops off on its own rather than needing a cleanup.
            $table->timestamp('expires_at')->nullable();

            $table->string('audience', 20)->default('all'); // all | branch | department
            // branches.id is unsignedInteger (manual IDs), NOT bigIncrements —
            // a foreignId() here fails with errno 3780 on MySQL.
            $table->unsignedInteger('audience_branch_id')->nullable()->index();
            $table->unsignedBigInteger('audience_department_id')->nullable()->index();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('created_by_name', 255)->nullable();

            $table->timestamps();

            // The slider's query: published, in-window, newest first.
            $table->index(['is_published', 'published_at']);
            $table->index(['is_published', 'expires_at']);
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('announcement_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamp('read_at');
            $table->timestamps();

            // One row per person per announcement — the unread count is a
            // NOT EXISTS against this, so a duplicate would skew it.
            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
    }
};
