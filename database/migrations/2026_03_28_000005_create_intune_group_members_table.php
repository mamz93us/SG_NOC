<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intune_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intune_group_id')->constrained()->cascadeOnDelete();
            $table->string('azure_user_id', 100);
            $table->string('user_upn', 150);
            $table->string('display_name', 150);
            $table->enum('status', ['pending', 'added', 'removed', 'error'])->default('pending');
            $table->timestamps();

            $table->unique(['intune_group_id', 'azure_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intune_group_members');
    }
};
