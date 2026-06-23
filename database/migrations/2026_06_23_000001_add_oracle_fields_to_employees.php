<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('oracle_emp_no')->nullable()->index()->after('azure_id');
            $table->string('oracle_dept_no')->nullable()->after('oracle_emp_no');
            $table->string('oracle_department')->nullable()->after('oracle_dept_no');
            $table->string('oracle_location')->nullable()->after('oracle_department');
            $table->string('mobile_phone')->nullable()->after('oracle_location');
            // Stamped whenever an Oracle import row is applied/created/linked to
            // this employee. NULL = never seen in any Oracle export → flagged as
            // "not in HR" on the reconciliation page.
            $table->timestamp('oracle_synced_at')->nullable()->after('mobile_phone');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['oracle_emp_no']);
            $table->dropColumn([
                'oracle_emp_no',
                'oracle_dept_no',
                'oracle_department',
                'oracle_location',
                'mobile_phone',
                'oracle_synced_at',
            ]);
        });
    }
};
