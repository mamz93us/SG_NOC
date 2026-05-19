<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();

            // employee_id is nullable so we can persist orphaned uploads (filename
            // didn't match any active employee) for manual reconciliation.
            $table->foreignId('employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->string('email', 191);                  // parsed from the filename
            $table->string('file_path', 255);              // path within the azure_certificates disk
            $table->string('file_mime', 100)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->unsignedInteger('file_size')->nullable();

            // 64-char unguessable URL token — primary access mechanism.
            $table->string('token', 64)->unique();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Only one certificate per (course, recipient).
            $table->unique(['course_id', 'email']);
            $table->index('employee_id');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_certificates');
    }
};
