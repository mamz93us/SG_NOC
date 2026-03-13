<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('azure_branch_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('keyword'); // 'JED', 'Jeddah', 'RUH'
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('azure_branch_mappings');
    }
};
