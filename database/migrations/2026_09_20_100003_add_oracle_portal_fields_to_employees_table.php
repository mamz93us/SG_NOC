<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fields Oracle's Employee Portal carries that the spreadsheet never did.
 *
 * Two naming decisions worth keeping:
 *
 * `name_ar` rather than `person_name_ar` — the column sits beside `name`, not
 * beside a `person_name` this table does not have. The precedent for an Arabic
 * name on a record here is Archive's `name_ar`. (Oracle's own spelling,
 * `person_name_ar`, stays on hr_import_rows, which mirrors the feed.)
 *
 * `oracle_employee_category` rather than `employee_category` — Oracle's
 * category for 144 people is the word SERVICE, and `Employee::TYPE_SERVICE`
 * already means something different and consequential here: holds no mailbox.
 * Anyone later writing `employee_type = $row->employee_category` would strip
 * 144 field engineers of their mailbox semantics in one statement. The
 * `oracle_` prefix matches the five Oracle-owned columns already on this
 * table and makes that line impossible to write by accident.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'name_ar',
        'oracle_employee_category',
        'oracle_person_id',
        'oracle_assignment_status',
        'oracle_person_type',
        'oracle_leaver_ignored_at',
    ];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'name_ar')) {
                $table->string('name_ar', 255)->nullable()->after('name');
            }

            if (! Schema::hasColumn('employees', 'oracle_employee_category')) {
                // SALES, SERVICE, OPERATION, ADMINISTRATORS, ENGINEER,
                // COLLECTOR, MARKETING, RETAIL. Indexed because it is a filter.
                $table->string('oracle_employee_category', 50)->nullable()->index();
            }

            if (! Schema::hasColumn('employees', 'oracle_person_id')) {
                // Oracle's 15-digit PERSON_ID. The only key its per-person
                // endpoints accept, and the join to vacation_employees.
                $table->string('oracle_person_id', 30)->nullable()->index();
            }

            if (! Schema::hasColumn('employees', 'oracle_assignment_status')) {
                // What Oracle last said: ACTIVE or INACTIVE. Deliberately NOT
                // `status`. This column is reportable and never authoritative:
                // a transition to status='terminated' disables the person's
                // Microsoft account, so it stays a human's decision.
                $table->string('oracle_assignment_status', 30)->nullable();
            }

            if (! Schema::hasColumn('employees', 'oracle_person_type')) {
                // Permanent Employee, Temporary Employee, External Permanent
                // Employee — the last two are contractors.
                $table->string('oracle_person_type', 50)->nullable();
            }

            if (! Schema::hasColumn('employees', 'oracle_leaver_ignored_at')) {
                // Somebody looked at "Oracle says this person is inactive" and
                // decided it was wrong — mid-transfer, or Oracle running ahead
                // of the real last day. Without this, the same row is offered
                // again every single run and the list stops being read.
                // Cleared automatically if Oracle says ACTIVE again.
                $table->timestamp('oracle_leaver_ignored_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
