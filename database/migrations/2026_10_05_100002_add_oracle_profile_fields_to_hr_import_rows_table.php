<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of Oracle's /employees row on the staged import row, so applying or
 * linking a row carries everything Oracle said about the person, not only what
 * the spreadsheet used to hold. A sheet row leaves all five null.
 */
return new class extends Migration
{
    private const TEXT = [
        'supervisor_email' => 255,
        'manager_email' => 255,
        'job_category' => 255,
        'profession' => 255,
    ];

    public function up(): void
    {
        Schema::table('hr_import_rows', function (Blueprint $table) {
            foreach (self::TEXT as $name => $length) {
                if (! Schema::hasColumn('hr_import_rows', $name)) {
                    $table->string($name, $length)->nullable();
                }
            }

            if (! Schema::hasColumn('hr_import_rows', 'contract_end_date')) {
                $table->date('contract_end_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_import_rows', function (Blueprint $table) {
            foreach ([...array_keys(self::TEXT), 'contract_end_date'] as $column) {
                if (Schema::hasColumn('hr_import_rows', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
