<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per backup file swept out of the SFTP inbox and pushed to
        // Azure Blob. The work runs in the sftp-backups:sweep scheduled command
        // (production has no queue worker, only the scheduler). Rows are kept as
        // an audit trail even after the blob is pruned (status=pruned).
        Schema::create('sftp_backups', function (Blueprint $table) {
            $table->id();
            $table->string('source')->nullable()->index();   // first inbox subfolder, e.g. "sophos-jed"
            $table->string('relative_path');                  // path under the inbox as pushed
            $table->string('filename');
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256', 64)->nullable()->index();
            $table->string('disk')->nullable();               // azure disk the blob lives on
            $table->string('azure_path')->nullable();         // deterministic blob key (relative to disk)
            $table->string('status')->default('pending')->index(); // pending|uploaded|failed|skipped|pruned
            $table->text('error')->nullable();
            $table->timestamp('received_at')->nullable();     // source file mtime
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();

            // azure_path is derived deterministically from the file (path +
            // mtime), so the same physical file always maps to the same row —
            // this makes the sweep idempotent and lets a failed upload retry on
            // the next tick without creating duplicates.
            $table->unique('azure_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sftp_backups');
    }
};
