<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every fetch of a firmware image, as recorded by nginx.
 *
 * The bytes are served by nginx, not Laravel, so the app never sees these
 * requests — `firmware:ingest-log` tails the firmware vhost's access log into
 * this table. That is the only way to answer "which phone took the firmware,
 * and when", and it is also where a mistyped filename shows up: a 404 here is a
 * phone asking for something we never published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_firmware_downloads', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->index();
            // Identity comes from the Grandstream User-Agent; absent for
            // anything else that pokes the directory.
            $table->string('mac', 12)->nullable()->index();
            $table->string('model', 60)->nullable();
            $table->string('firmware_version', 40)->nullable();
            $table->string('filename', 160)->index();
            $table->unsignedSmallInteger('status');
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('requested_at')->index();
            // Guards against double-ingest if the resume state is ever lost.
            $table->char('line_hash', 40)->unique();
            $table->timestamps();

            $table->index(['filename', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_firmware_downloads');
    }
};
