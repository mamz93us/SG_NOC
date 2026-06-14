<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Download Center — one row per file an admin puts into Azure Blob, either by
 * direct upload or by pasting a URL the NOC fetches server-side. The blob lives
 * on the `azure_downloads` disk (downloads/ prefix); the file is served back
 * through auth-gated NOC streams and, optionally, a tokenised public link with
 * an optional expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_files', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('original_filename');
            $table->string('disk')->default('azure_downloads');
            $table->string('azure_path')->nullable()->unique();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('mime')->nullable();
            $table->string('sha256', 64)->nullable();

            // Ingest: 'upload' (streamed straight to Azure) or 'url' (fetched
            // async by the downloads:fetch-remote command).
            $table->string('source')->default('upload');
            $table->text('source_url')->nullable();
            $table->string('status')->default('stored')->index(); // pending|fetching|stored|failed
            $table->text('error')->nullable();

            // Public share link — opt-in, optional expiry, revocable.
            $table->boolean('public_enabled')->default(false);
            $table->string('public_token')->nullable()->unique();
            $table->timestamp('public_expires_at')->nullable();

            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();

            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_files');
    }
};
