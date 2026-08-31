<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local cache of the company's shared Microsoft 365 calendar.
 *
 * The home portal is opened by the whole company within minutes of 9am, so it
 * must never call Graph on request — `company-calendar:sync` refills this table
 * on a schedule and the page reads only from here.
 *
 * Microsoft owns these events; this table is disposable. `external_id` is the
 * Graph event id and is what makes the sync idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_events', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 512)->unique();
            $table->string('subject', 300);
            $table->text('body_preview')->nullable();
            $table->string('location', 300)->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_all_day')->default(false);
            $table->string('organizer', 255)->nullable();
            $table->string('web_link', 1000)->nullable();
            // Stamped each sync; rows not seen in the window are pruned, which
            // is how a cancelled meeting disappears.
            $table->timestamp('synced_at')->index();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_events');
    }
};
