<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_floors', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id')->index();
            $table->string('name', 100);          // e.g. "Ground Floor", "Floor 1", "Basement"
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_floors');
    }
};
