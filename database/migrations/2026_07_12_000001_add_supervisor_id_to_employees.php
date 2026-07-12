<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Oracle "supervisor" line to employees. This is distinct from
 * manager_id: the Oracle HRMS export carries both a SUPERVISOR (direct line)
 * and a MANAGER (higher-up), and they differ for the majority of staff.
 * Self-referential, nullable, mirrors the manager_id definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('supervisor_id')->nullable()->index()->after('manager_id');
            $table->foreign('supervisor_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['supervisor_id']);
            $table->dropColumn('supervisor_id');
        });
    }
};
