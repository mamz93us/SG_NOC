<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internet_access_levels', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);              // Display name shown in manager form
            $table->string('description', 255)->nullable();
            $table->string('azure_group_id', 100)->nullable();   // Azure AD group Object ID
            $table->string('azure_group_name', 255)->nullable();  // Human-readable group name (cached)
            $table->boolean('is_default')->default(false);        // Pre-selected in manager form
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internet_access_levels');
    }
};
