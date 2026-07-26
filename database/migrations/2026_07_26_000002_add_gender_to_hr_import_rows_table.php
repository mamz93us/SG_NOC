<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HR export now carries GENDER; stage it per-row so the preview can show the
 * change and applying a batch writes it onto the employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_import_rows', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('job_name'); // male|female
        });
    }

    public function down(): void
    {
        Schema::table('hr_import_rows', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
