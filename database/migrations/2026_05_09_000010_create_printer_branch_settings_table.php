<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_branch_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id')->unique();
            $table->string('manager_email', 190)->nullable();
            $table->string('manager_name', 190)->nullable();
            $table->boolean('alerts_enabled')->default(true);
            $table->unsignedTinyInteger('toner_warning_threshold')->nullable();
            $table->unsignedTinyInteger('toner_critical_threshold')->nullable();
            $table->unsignedTinyInteger('waste_critical_threshold')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_branch_settings');
    }
};
