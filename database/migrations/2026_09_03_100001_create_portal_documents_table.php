<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee document library — manuals, guides, IT policies and forms — read
 * on the home portal and authored in Admin → Employee Documents.
 *
 * A document is EITHER an uploaded file or a link to one that already lives
 * somewhere else (SharePoint, a vendor manual). Both are modelled here rather
 * than in two tables: to the person reading the portal they are the same thing,
 * and the card only differs by whether it downloads or navigates away.
 *
 * Files land on the `private` disk and are streamed by a controller that
 * re-checks publication and audience on every hit. Nothing here is servable
 * directly — an IT policy is not a public asset, and `storage/app/public` would
 * make it one.
 *
 * Audience is the same coarse triple as announcements (everyone / one branch /
 * one department), on purpose: the two are authored by the same people and a
 * second, different targeting model would be a trap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_documents', function (Blueprint $table) {
            $table->id();

            $table->string('title', 200);
            $table->string('title_ar', 200)->nullable();
            $table->string('description', 500)->nullable();

            // manual | policy | form | guide — see PortalDocument::CATEGORIES.
            // The portal groups by this and the IT Policy card filters on it.
            $table->string('category', 20)->default('manual');

            // One of these two is always set; the model enforces it.
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('link_url', 500)->nullable();

            // Shown next to the title. A policy nobody can date is a policy
            // nobody trusts.
            $table->string('version', 32)->nullable();
            $table->date('effective_date')->nullable();

            $table->boolean('is_published')->default(false);
            $table->boolean('pinned')->default(false);
            $table->integer('sort_order')->default(0);

            $table->string('audience', 20)->default('all'); // all | branch | department
            // branches.id is unsignedInteger (manual IDs), NOT bigIncrements —
            // a foreignId() here fails with errno 3780 on MySQL.
            $table->unsignedInteger('audience_branch_id')->nullable()->index();
            $table->unsignedBigInteger('audience_department_id')->nullable()->index();

            $table->unsignedBigInteger('download_count')->default(0);

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('created_by_name', 255)->nullable();

            $table->timestamps();

            // The portal's query: published, by category, in author's order.
            $table->index(['is_published', 'category']);
            $table->index(['category', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_documents');
    }
};
