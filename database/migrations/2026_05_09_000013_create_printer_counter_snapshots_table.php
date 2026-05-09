<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_counter_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('printer_id');
            $table->date('snapshot_date');
            $table->unsignedInteger('page_total')->nullable();
            $table->unsignedInteger('page_color')->nullable();
            $table->unsignedInteger('page_mono')->nullable();
            $table->unsignedInteger('page_copy')->nullable();
            $table->unsignedInteger('page_print')->nullable();
            $table->unsignedInteger('page_scan')->nullable();
            $table->unsignedInteger('page_fax')->nullable();
            $table->timestamps();

            $table->foreign('printer_id')->references('id')->on('printers')->cascadeOnDelete();
            $table->unique(['printer_id', 'snapshot_date']);
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_counter_snapshots');
    }
};
