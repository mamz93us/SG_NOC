<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an HR import batch come from the Employee Portal API as well as from a
 * spreadsheet.
 *
 * The staging tables are reused rather than replaced because the review step
 * IS the safety mechanism: matchEmployee() needs two signals to agree and
 * refuses to guess between them, and what it refuses has to land in front of a
 * person. A direct-write sync would have to invent somewhere for ambiguity to
 * go, and ambiguity is guaranteed here — the SSS Egypt and SamirGroup EMP_NO
 * series collide.
 *
 * `filename` on hr_import_batches is NOT NULL, so an API pull passes a label
 * rather than needing a schema change; `uploaded_by` is already nullable,
 * which an unattended run needs.
 */
return new class extends Migration
{
    private const ROW_COLUMNS = [
        'person_id' => 30,
        'person_name_ar' => 255,
        'employee_category' => 50,
        'assignment_status' => 30,
        'person_type' => 50,
        'assignment_id' => 30,
        'supervisor_name' => 255,
        'manager_name' => 255,
    ];

    public function up(): void
    {
        Schema::table('hr_import_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_import_batches', 'source')) {
                // 'sheet' | 'api', mirroring vacation_imports.source.
                $table->string('source', 20)->default('sheet')->index();
            }

            if (! Schema::hasColumn('hr_import_batches', 'source_digest')) {
                // md5 of the normalised rows. Oracle is read daily but changes
                // rarely; without this the review page fills with identical
                // batches and stops being worth opening.
                $table->char('source_digest', 32)->nullable();
            }
        });

        Schema::table('hr_import_rows', function (Blueprint $table) {
            foreach (self::ROW_COLUMNS as $name => $length) {
                if (! Schema::hasColumn('hr_import_rows', $name)) {
                    $table->string($name, $length)->nullable();
                }
            }

            if (! Schema::hasColumn('hr_import_rows', 'hire_date')) {
                $table->date('hire_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_import_batches', function (Blueprint $table) {
            foreach (['source', 'source_digest'] as $column) {
                if (Schema::hasColumn('hr_import_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('hr_import_rows', function (Blueprint $table) {
            foreach ([...array_keys(self::ROW_COLUMNS), 'hire_date'] as $column) {
                if (Schema::hasColumn('hr_import_rows', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
