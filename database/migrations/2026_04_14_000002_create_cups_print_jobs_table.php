<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cups_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cups_printer_id')->constrained('cups_printers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('cups_job_id')->nullable();
            $table->string('title')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('pages')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->json('cups_metadata')->nullable();
            $table->timestamps();

            $table->index('cups_printer_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cups_print_jobs');
    }
};
