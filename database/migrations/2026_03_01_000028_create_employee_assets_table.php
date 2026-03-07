<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('devices')->cascadeOnDelete();
            $table->date('assigned_date');
            $table->date('returned_date')->nullable();
            $table->enum('condition', ['good', 'fair', 'poor'])->default('good');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_assets');
    }
};
