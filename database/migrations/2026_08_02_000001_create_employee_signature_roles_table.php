<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra signature "roles" for an employee who holds more than one role under the
 * same mailbox. Each row is an ADDITIONAL classic-Outlook signature that differs
 * only in job title + department; the employee's own job_title/department remains
 * the default (primary) signature. Classic Outlook only — the New Outlook / OWA /
 * mobile transport rule still stamps the single Azure Title/Department (primary).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_signature_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->string('job_title', 255)->nullable();
            $table->string('department', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_signature_roles');
    }
};
