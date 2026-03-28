<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intune_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('azure_group_id', 100)->nullable()->unique();
            $table->enum('group_type', ['printer', 'policy', 'device', 'compliance'])->default('printer');
            $table->unsignedInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->enum('sync_status', ['pending', 'synced', 'error'])->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intune_groups');
    }
};
