<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_backups', function (Blueprint $table) {
            $table->id();
            $table->string('database')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('disk')->nullable();
            $table->string('azure_path')->nullable()->unique();
            $table->string('status')->default('pending')->index(); // pending|running|uploaded|failed|pruned
            $table->text('error')->nullable();
            $table->string('triggered_via')->default('scheduled'); // scheduled|manual
            $table->unsignedBigInteger('initiated_by')->nullable(); // users.id for manual runs
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_backups');
    }
};
