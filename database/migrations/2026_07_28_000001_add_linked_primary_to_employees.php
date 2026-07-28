<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a secondary sign-in account (e.g. a SamirGroup mailbox) to the primary
 * employee record (e.g. the person's SSS HR record). The secondary inherits the
 * primary's HR fields (job title, department, extension, mobile) for signatures
 * and Azure contact sync, while keeping its own branch (JED), name, and email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('linked_primary_employee_id')->nullable()->after('supervisor_id');
            $table->foreign('linked_primary_employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->index('linked_primary_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['linked_primary_employee_id']);
            $table->dropIndex(['linked_primary_employee_id']);
            $table->dropColumn('linked_primary_employee_id');
        });
    }
};
