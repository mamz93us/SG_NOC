<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Oracle's /employees list carries that the employee record had nowhere
 * to keep: the job category, the profession, the contract end date, and the
 * manager and supervisor as Oracle names them.
 *
 * All seven are Oracle's words, recorded as said. Nothing reads them to decide
 * anything, which is why the sync may write them without review.
 *
 * `oracle_job_category` is not `oracle_employee_category`. That one came from
 * the /attendance view Oracle retired on 2026-09-27 (SALES, SERVICE, …, eight
 * values); this is the job's family in the new list (Marketing, Engineers,
 * AppDev&prog&analysis, …, eighteen). They are different classifications of
 * the same people, so neither is written over the other.
 *
 * The manager and supervisor are kept as a name and an address rather than as
 * `manager_id` / `supervisor_id`. Those two decide whose attendance and leave
 * somebody may read, and where an approval goes; Oracle disagreeing with the
 * NOC about one of them (28 managers and 38 supervisors on 2026-10-05) is
 * something for a person to settle, not a scheduled job.
 */
return new class extends Migration
{
    private const TEXT = [
        'oracle_job_category' => 255,
        'oracle_profession' => 255,
        'oracle_manager_name' => 255,
        'oracle_manager_email' => 255,
        'oracle_supervisor_name' => 255,
        'oracle_supervisor_email' => 255,
    ];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach (self::TEXT as $name => $length) {
                if (! Schema::hasColumn('employees', $name)) {
                    $table->string($name, $length)->nullable();
                }
            }

            if (! Schema::hasColumn('employees', 'oracle_contract_end_date')) {
                // Oracle's date, never the NOC's `terminated_date`: 94 people
                // were Active on 2026-10-05 with this date already behind them.
                $table->date('oracle_contract_end_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach ([...array_keys(self::TEXT), 'oracle_contract_end_date'] as $column) {
                if (Schema::hasColumn('employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
