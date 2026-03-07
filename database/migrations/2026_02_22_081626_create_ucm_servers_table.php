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
        Schema::create('ucm_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');           // e.g. https://192.168.1.100:8089
            $table->string('api_username');  // API login username
            $table->string('api_password');  // API login password
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ucm_servers');
    }
};
