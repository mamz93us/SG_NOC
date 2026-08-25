<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grandstream firmware library. One row per (model, version) image the NOC holds.
 *
 * `filename` is the exact name the phone asks for (e.g. grp2601fw.bin) — it comes
 * out of the vendor ZIP and must never be renamed, because the phone requests that
 * literal name from the firmware server path. `path` is where the file sits on the
 * `firmware` disk; only the *active* row per model is published at the disk root,
 * which is what nginx serves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_firmwares', function (Blueprint $table) {
            $table->id();
            $table->string('model', 60)->index();          // GRP2601 / GXP1780 …
            $table->string('version', 40);                 // 1.0.13.59
            $table->string('filename', 120);               // grp2601fw.bin
            // Which phone models this image applies to. One Grandstream image
            // usually covers a whole family (GRP2601 / 2601P / 2601W), and only
            // the vendor knows the grouping — so it is an explicit, editable list
            // rather than something guessed from the filename. Comma-separated
            // tokens, `*` allowed as a suffix wildcard: "GRP2601*,GRP2602*".
            $table->string('covers', 255)->nullable();
            $table->string('path', 255)->nullable();       // library/<id>/<filename>
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('source', 20)->default('upload');   // upload | url
            $table->string('source_url', 2048)->nullable();
            $table->string('status', 20)->default('stored');   // pending|fetching|stored|failed
            $table->text('error')->nullable();
            $table->unsignedBigInteger('download_total_bytes')->nullable();
            $table->unsignedBigInteger('download_received_bytes')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['model', 'version']);
            $table->index(['model', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_firmwares');
    }
};
