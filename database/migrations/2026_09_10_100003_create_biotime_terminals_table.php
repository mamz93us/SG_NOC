<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fingerprint terminals seen in punches, with the last punch each one sent —
 * a terminal that goes quiet on a work day is a device problem, not absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biotime_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biotime_source_id')->constrained('biotime_sources');
            $table->string('terminal_sn', 100);
            $table->string('terminal_alias', 150)->nullable();
            $table->string('area_alias', 100)->nullable();
            $table->dateTime('last_punch_at')->nullable();
            $table->timestamps();

            $table->unique(['biotime_source_id', 'terminal_sn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biotime_terminals');
    }
};
