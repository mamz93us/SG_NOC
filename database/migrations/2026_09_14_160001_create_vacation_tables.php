<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vacations: Oracle's leave balances and leave records, per employee.
 *
 * - vacation_employees: an Oracle person number in one book and the NOC
 *   employee it is. Linked by Services\Vacation\VacationLinker; a manual link
 *   is HR's decision and never overwritten.
 * - vacation_balances: one row per person per year, Oracle's own figures as of
 *   a date. `used` is Oracle's ABSENCES with the sign flipped; `balance` is
 *   Oracle's TOTAL_BALANCE, never recomputed here.
 * - vacation_absences: one row per leave record. A record Oracle no longer
 *   lists is stamped removed_at, never deleted, so a partial export cannot
 *   destroy history and the next full one restores it.
 * - vacation_imports: one row per sheet (or API call) with what it changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacation_imports', function (Blueprint $table) {
            $table->id();
            $table->string('book', 40);
            $table->string('kind', 20);
            $table->string('source', 20)->default('sheet');
            $table->string('filename')->nullable();
            $table->date('as_of')->nullable();
            $table->date('window_from')->nullable();
            $table->date('window_to')->nullable();
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('removed')->default(0);
            $table->unsignedInteger('restored')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('unlinked')->default(0);
            $table->json('notes')->nullable();
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->timestamps();

            $table->index(['book', 'kind', 'created_at']);
        });

        Schema::create('vacation_employees', function (Blueprint $table) {
            $table->id();
            $table->string('book', 40);
            $table->string('oracle_emp_no', 50);
            $table->string('oracle_person_id', 30)->nullable();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->string('match_method', 30)->nullable();
            $table->json('candidate_ids')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['book', 'oracle_emp_no']);
        });

        Schema::create('vacation_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vacation_employee_id');
            $table->foreign('vacation_employee_id')->references('id')->on('vacation_employees')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('carryover', 10, 4)->nullable();
            $table->decimal('accrued', 10, 4)->nullable();
            $table->decimal('used', 10, 4)->nullable();
            $table->decimal('balance', 10, 4)->nullable();
            $table->date('as_of');
            $table->unsignedBigInteger('vacation_import_id')->nullable();
            $table->timestamps();

            $table->unique(['vacation_employee_id', 'year']);
        });

        Schema::create('vacation_absences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vacation_employee_id');
            $table->foreign('vacation_employee_id')->references('id')->on('vacation_employees')->cascadeOnDelete();
            $table->string('absence_type', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('calendar_days');
            $table->unsignedSmallInteger('work_days');
            $table->decimal('duration', 8, 2)->nullable();
            $table->unsignedBigInteger('first_import_id')->nullable();
            $table->unsignedBigInteger('last_import_id')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['vacation_employee_id', 'absence_type', 'start_date', 'end_date'], 'vacation_absences_record_unique');
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacation_absences');
        Schema::dropIfExists('vacation_balances');
        Schema::dropIfExists('vacation_employees');
        Schema::dropIfExists('vacation_imports');
    }
};
