<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The pictures inlined in Oracle's announcements.
 *
 * Since Oracle's release of 2026-09-27 each announcement's description is its
 * HTML with the designed picture inlined — the picture that is the notice for
 * 57 of 61. AnnouncementSync keeps them here, and the home portal serves them
 * through a route that checks the viewer may see the notice.
 *
 * In the database rather than on the private disk because the scheduler that
 * writes them runs as azureuser and PHP-FPM that serves them as www-data: a
 * directory either one creates, the other cannot open.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('announcement_images')) {
            Schema::create('announcement_images', function (Blueprint $table) {
                $table->id();
                $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
                $table->unsignedTinyInteger('position')->default(0);
                $table->string('mime', 40);
                $table->char('sha1', 40);
                $table->unsignedInteger('size');
                $table->binary('bytes');
                $table->timestamps();

                $table->index(['announcement_id', 'position']);
            });
        }

        // Laravel's binary is a BLOB on MySQL: 64 KB, and the pictures run to
        // ~550 KB. SQLite's has no such limit, and no MODIFY either.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE announcement_images MODIFY bytes LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_images');
    }
};
