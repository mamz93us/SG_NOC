<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the people who work for the company but hold no mailbox — drivers,
 * guards, warehouse and cleaning staff. Oracle HRMS knows them by EMP_NO only,
 * and the NOC needs them for one reason: their fingerprint punches.
 *
 * Kept apart from `status`, which is an enum of active / terminated / on_leave
 * and answers a different question. A service employee is active or terminated
 * like anyone else; what makes them different is having no Entra account, so
 * every flow gated on `azure_id` skips them without being told to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employee_type', 20)->default('standard')->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['employee_type']);
            $table->dropColumn('employee_type');
        });
    }
};
