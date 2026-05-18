<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isp_provider_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('isp_provider_id')->constrained('isp_providers')->cascadeOnDelete();
            $table->string('name');
            $table->integer('speed_down')->nullable();
            $table->integer('speed_up')->nullable();
            $table->decimal('monthly_cost', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['isp_provider_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isp_provider_packages');
    }
};
