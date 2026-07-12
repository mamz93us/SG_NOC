<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Oracle HRMS department code (DEPT_NO) lives on employees after the HR
 * import; give the Department master its own column so the code is visible and
 * manageable on the Departments settings page. Backfilled separately from the
 * (1:1) employee → department mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('oracle_dept_no')->nullable()->index()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropIndex(['oracle_dept_no']);
            $table->dropColumn('oracle_dept_no');
        });
    }
};
