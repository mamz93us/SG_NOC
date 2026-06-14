<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop / lock-screen wallpaper sets, one row per AD domain. The actual images
 * live on the `public` disk (wallpapers/{id}/…) so they have a stable, public,
 * unauthenticated URL that Intune-managed devices can fetch with no credentials.
 *
 * A device's joined domain (e.g. sssegypt.com) is matched against `domain_match`;
 * if nothing matches, the row flagged `is_default` is used. The NOC exposes a
 * public manifest (image URLs + sha256 hashes); the per-device PowerShell script
 * re-downloads only when the hash changes, so editing a wallpaper here propagates
 * to every device on the next daily run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallpaper_sets', function (Blueprint $table) {
            $table->id();
            $table->string('label');                          // "SSS Egypt"
            $table->string('domain_match')->unique();         // "sssegypt.com" (matched case-insensitive)
            $table->boolean('is_default')->default(false);    // fallback when no domain matches
            $table->boolean('enabled')->default(true);

            $table->string('desktop_path')->nullable();       // public-disk path
            $table->string('desktop_hash', 64)->nullable();   // sha256 of current image
            $table->string('lockscreen_path')->nullable();
            $table->string('lockscreen_hash', 64)->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
        });

        // Seed the two known domains so the admin only has to upload images.
        DB::table('wallpaper_sets')->insert([
            [
                'label' => 'SSS Egypt',
                'domain_match' => 'sssegypt.com',
                'is_default' => true,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'label' => 'Samir Group',
                'domain_match' => 'samirgroup.com',
                'is_default' => false,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('wallpaper_sets');
    }
};
