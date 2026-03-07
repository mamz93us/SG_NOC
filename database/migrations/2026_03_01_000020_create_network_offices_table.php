<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_offices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('floor_id')->index();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('floor_id')
                  ->references('id')->on('network_floors')
                  ->cascadeOnDelete();

            $table->unique(['floor_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_offices');
    }
};
