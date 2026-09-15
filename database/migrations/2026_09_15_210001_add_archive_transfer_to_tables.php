<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: moving ArcMate's files into the NOC's own Azure storage.
 *
 * 372 GB in 622,000 files, of which SPS Invoices is 365 GB. That is nights of
 * copying, so the transfer is a worker with a window and a speed cap rather
 * than a button that runs until something times out — and every one of those
 * settings is on the source row so it can be changed from the Transfer page
 * without a deploy.
 *
 * The per-file columns are what make the move safe to interrupt. A file is only
 * marked as living in Azure after its copy has been read back and checked, so a
 * crash mid-transfer leaves it exactly where it was: on the ArcMate share,
 * still being served from there, queued to try again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archive_sources', function (Blueprint $table) {
            $table->boolean('transfer_enabled')->default(true)->after('enabled');
            // Nights and the Saudi weekend by default. The share is on the
            // server people are still scanning into all day; saturating it
            // during working hours would be felt by the people the portal is
            // supposed to be helping.
            $table->string('transfer_window_start', 5)->default('19:00')->after('transfer_enabled');
            $table->string('transfer_window_end', 5)->default('07:00')->after('transfer_window_start');
            $table->boolean('transfer_weekend_all_day')->default(true)->after('transfer_window_end');
            $table->boolean('transfer_anytime')->default(false)->after('transfer_weekend_all_day');
            $table->unsignedInteger('transfer_speed_mbps')->nullable()->after('transfer_anytime');
        });

        Schema::table('archives', function (Blueprint $table) {
            $table->boolean('transfer_paused')->default(false)->after('byte_total');
            // Lower runs first. SPS Invoices is the live archive and the one
            // people would miss, so it is worth moving before the history.
            $table->unsignedInteger('transfer_priority')->default(100)->after('transfer_paused');
        });

        Schema::table('archive_files', function (Blueprint $table) {
            $table->timestamp('transferred_at')->nullable()->after('sha256');
            $table->unsignedTinyInteger('transfer_attempts')->default(0)->after('transferred_at');
            $table->text('transfer_error')->nullable()->after('transfer_attempts');

            // The worker's queue: files still on the share, least-tried first.
            $table->index(['disk', 'transfer_attempts'], 'archive_files_transfer_queue_index');
        });

        // One row per run, which is where the speed and the time remaining on
        // the Transfer page come from. Measured rather than estimated: a
        // guess at "4 to 10 nights" is not something to show somebody watching
        // a progress bar.
        Schema::create('archive_transfer_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('files_done')->default(0);
            $table->unsignedBigInteger('bytes_done')->default(0);
            $table->unsignedInteger('files_failed')->default(0);
            $table->decimal('avg_mbps', 8, 2)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_transfer_runs');

        Schema::table('archive_files', function (Blueprint $table) {
            $table->dropIndex('archive_files_transfer_queue_index');
            $table->dropColumn(['transferred_at', 'transfer_attempts', 'transfer_error']);
        });

        Schema::table('archives', function (Blueprint $table) {
            $table->dropColumn(['transfer_paused', 'transfer_priority']);
        });

        Schema::table('archive_sources', function (Blueprint $table) {
            $table->dropColumn([
                'transfer_enabled',
                'transfer_window_start',
                'transfer_window_end',
                'transfer_weekend_all_day',
                'transfer_anytime',
                'transfer_speed_mbps',
            ]);
        });
    }
};
