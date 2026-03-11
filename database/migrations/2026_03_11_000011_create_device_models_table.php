<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('device_type')->nullable();          // ucm, switch, router, ap, etc.
            $table->string('latest_firmware', 100)->nullable(); // latest known firmware for this model
            $table->text('release_notes')->nullable();
            $table->timestamps();

            $table->unique(['name', 'manufacturer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_models');
    }
};
