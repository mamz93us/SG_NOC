<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nationality, as Oracle holds it.
 *
 * Oracle's Employee Portal API does not send it (its EmployeeDTO has no such
 * field), so it arrives from Oracle's own export — PERSON_NUMBER and
 * SYSTEM_NATIONALITY — through `employees:import-nationalities`. The `oracle_`
 * prefix is the same promise as on the columns beside it: Oracle's word,
 * recorded as said, and replaced by the next export rather than edited here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'oracle_nationality')) {
                $table->string('oracle_nationality', 100)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'oracle_nationality')) {
                $table->dropColumn('oracle_nationality');
            }
        });
    }
};
