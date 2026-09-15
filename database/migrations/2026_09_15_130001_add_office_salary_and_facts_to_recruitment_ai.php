<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment AI: the office a job is in and its salary budget, set on the
 * job's page because Teamtailor rarely has them (on 2026-09-15 two of eleven
 * jobs named a location and none a salary range); and each screening's facts —
 * the salary the applicant asked for and has now, where they live and the
 * estimated distance to the office.
 *
 * `facts` is personal (salary, home location) and encrypted: see the model cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_jobs', function (Blueprint $table) {
            $table->unsignedInteger('office_branch_id')->nullable()->after('must_haves');
            $table->unsignedInteger('salary_budget_min')->nullable()->after('office_branch_id');
            $table->unsignedInteger('salary_budget_max')->nullable()->after('salary_budget_min');
            $table->string('salary_currency', 3)->nullable()->after('salary_budget_max');
        });

        Schema::table('recruitment_screenings', function (Blueprint $table) {
            $table->longText('facts')->nullable()->after('evaluation');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_screenings', function (Blueprint $table) {
            $table->dropColumn('facts');
        });

        Schema::table('recruitment_jobs', function (Blueprint $table) {
            $table->dropColumn(['office_branch_id', 'salary_budget_min', 'salary_budget_max', 'salary_currency']);
        });
    }
};
