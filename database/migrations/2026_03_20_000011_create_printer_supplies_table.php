<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            $table->string('supply_oid')->nullable();          // OID polled for current level
            $table->string('supply_capacity_oid')->nullable(); // OID polled for max capacity
            $table->unsignedSmallInteger('supply_index')->nullable(); // OID table index
            $table->string('supply_type')->default('toner');   // toner, drum, fuser, ink, waste, ribbon, maintenance
            $table->string('supply_color')->nullable();        // black, cyan, magenta, yellow, waste, none
            $table->string('supply_descr')->nullable();        // Raw description from printer
            $table->unsignedInteger('supply_capacity')->nullable();   // Max capacity (raw units)
            $table->integer('supply_current')->nullable();     // Current level (raw units, can be -1/-2/-3)
            $table->unsignedTinyInteger('supply_percent')->nullable(); // Normalized 0-100
            $table->string('part_number')->nullable();         // Consumable part number if available
            $table->unsignedTinyInteger('warning_threshold')->default(20);
            $table->unsignedTinyInteger('critical_threshold')->default(5);
            // Consumption tracking
            $table->float('consumption_rate')->nullable();     // % per day consumed (rolling average)
            $table->unsignedSmallInteger('estimated_days_remaining')->nullable(); // computed
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();

            $table->index(['printer_id', 'supply_type']);
            $table->index(['printer_id', 'supply_color']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_supplies');
    }
};
