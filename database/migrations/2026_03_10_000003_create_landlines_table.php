<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlines', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->string('phone_number');
            $table->string('provider')->nullable();
            $table->string('fxo_port')->nullable();
            $table->foreignId('gateway_id')->nullable()->constrained('ucm_servers')->nullOnDelete();
            $table->string('status')->default('active');     // active, disconnected, spare
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('branch_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlines');
    }
};
