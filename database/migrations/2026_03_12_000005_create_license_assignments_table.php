<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('license_id');
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->date('assigned_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('license_id')->references('id')->on('licenses')->cascadeOnDelete();
            $table->index(['assignable_type', 'assignable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_assignments');
    }
};
