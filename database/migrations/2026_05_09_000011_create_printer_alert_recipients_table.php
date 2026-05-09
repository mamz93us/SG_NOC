<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_alert_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email', 190)->nullable();
            $table->string('name', 190)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_alert_recipients');
    }
};
