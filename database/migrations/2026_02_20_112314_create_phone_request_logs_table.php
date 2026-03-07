<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
 public function up(): void
{
    Schema::create('phone_request_logs', function (Blueprint $table) {
        $table->id();
        $table->string('ip', 45);
        $table->string('user_agent')->nullable();
        $table->string('mac', 50)->nullable();
        $table->string('model', 100)->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phone_request_logs');
    }
};
