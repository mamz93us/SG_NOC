<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_racks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('floor_id')->index();
            $table->string('name', 100);          // e.g. "Rack A", "Rack 1", "Main Rack"
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable(); // U (rack units) capacity
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('floor_id')->references('id')->on('network_floors')->cascadeOnDelete();
            $table->unique(['floor_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_racks');
    }
};
