<?php

use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceTask;
use App\Models\Employee;
use App\Services\People\EmployeeMerger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Unit\Rbac\RbacTestSchema;

/**
 * Merging two records of one person: everything on the duplicate ends up on
 * the record kept, the kept record gains what it lacked and loses nothing, the
 * merges that would join two people are refused, and no table that points at
 * employees is forgotten.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    RbacTestSchema::create();

    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('azure_id', 36)->nullable();
        $t->string('employee_type')->default('standard');
        $t->string('oracle_emp_no')->nullable();
        $t->string('oracle_department')->nullable();
        $t->string('name');
        $t->string('email')->nullable();
        $t->unsignedBigInteger('branch_id')->nullable();
        $t->unsignedBigInteger('manager_id')->nullable();
        $t->unsignedBigInteger('supervisor_id')->nullable();
        $t->unsignedBigInteger('linked_primary_employee_id')->nullable();
        $t->string('job_title')->nullable();
        $t->string('mobile_phone')->nullable();
        $t->string('card_token')->nullable()->unique();
        $t->string('status')->default('active');
        $t->date('hired_date')->nullable();
        $t->timestamps();
    });
    Schema::create('identity_users', function (Blueprint $t) {
        $t->id();
        $t->string('azure_id', 36)->unique();
        $t->timestamps();
    });
    Schema::create('license_assignments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('license_id');
        $t->string('assignable_type');
        $t->unsignedBigInteger('assignable_id');
        $t->date('assigned_date');
        $t->timestamps();
    });
    Schema::create('attendance_days', function (Blueprint $t) {
        $t->id();
        $t->string('subject_key');
        $t->unsignedBigInteger('employee_id')->nullable();
        $t->date('work_date');
        $t->unique(['subject_key', 'work_date']);
    });
    Schema::create('attendance_punches', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('employee_id')->nullable();
        $t->dateTime('punch_time');
    });
    Schema::create('biotime_employees', function (Blueprint $t) {
        $t->id();
        $t->string('emp_code');
        $t->unsignedBigInteger('employee_id')->nullable();
    });
    Schema::create('employee_app_accounts', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('employee_id');
        $t->unsignedBigInteger('business_app_id');
        $t->unique(['employee_id', 'business_app_id']);
    });
    Schema::create('attendance_tasks', function (Blueprint $t) {
        $t->id();
        $t->string('type', 20);
        $t->json('payload')->nullable();
        $t->string('label');
        $t->string('status', 20)->default('pending');
        $t->unsignedBigInteger('requested_by')->nullable();
        $t->timestamps();
    });
});

afterEach(function () {
    foreach (['attendance_tasks', 'employee_app_accounts', 'biotime_employees', 'attendance_punches', 'attendance_days', 'license_assignments', 'identity_users', 'employees'] as $table) {
        Schema::dropIfExists($table);
    }
    RbacTestSchema::drop();
});

function mergeRecord(array $attributes): Employee
{
    // Without events: EmployeeObserver reconciles marketing lists, which needs tables these tests do not build.
    $employee = Employee::withoutEvents(fn () => Employee::create(array_merge(['status' => 'active'], $attributes)));
    if (! empty($attributes['azure_id']) && ! str_starts_with($attributes['azure_id'], 'gone-')) {
        DB::table('identity_users')->insert(['azure_id' => $attributes['azure_id']]);
    }

    return $employee;
}

it('moves the Oracle number, punches, attendance and BioTime codes onto the mailbox record and deletes the service record', function () {
    $mailbox = mergeRecord(['name' => 'Ibrahim Syed', 'email' => 'ibrahim.syed@samirgroup.com', 'azure_id' => 'az-285', 'job_title' => 'System Engineer']);
    $service = mergeRecord(['name' => 'Ibrahim Syed', 'employee_type' => 'service', 'oracle_emp_no' => '2693', 'oracle_department' => 'Dental',
        'job_title' => 'IT Engineer', 'mobile_phone' => '+966500000001', 'hired_date' => '2021-03-01', 'manager_id' => 42]);
    $report = mergeRecord(['name' => 'Someone Reporting', 'manager_id' => $service->id]);

    DB::table('license_assignments')->insert(['license_id' => 7, 'assignable_type' => Employee::class, 'assignable_id' => $mailbox->id, 'assigned_date' => '2026-01-01']);
    DB::table('attendance_punches')->insert([['employee_id' => $service->id, 'punch_time' => '2026-09-15 08:01:00'], ['employee_id' => $service->id, 'punch_time' => '2026-09-16 07:58:00']]);
    DB::table('attendance_days')->insert([
        ['subject_key' => 'emp:'.$service->id, 'employee_id' => $service->id, 'work_date' => '2026-09-15'],
        ['subject_key' => 'emp:'.$service->id, 'employee_id' => $service->id, 'work_date' => '2026-09-16'],
    ]);
    DB::table('biotime_employees')->insert(['emp_code' => '2693', 'employee_id' => $service->id]);

    [$keep, $duplicates] = EmployeeMerger::order([$service, $mailbox]);
    $result = (new EmployeeMerger)->merge($keep, $duplicates[0]);

    $mailbox->refresh();
    expect($keep->id)->toBe($mailbox->id)
        ->and($result['merged'])->toBeTrue()
        ->and(Employee::find($service->id))->toBeNull()
        ->and($mailbox->oracle_emp_no)->toBe('2693')
        ->and($mailbox->oracle_department)->toBe('Dental')
        ->and($mailbox->mobile_phone)->toBe('+966500000001')
        ->and((int) $mailbox->manager_id)->toBe(42)
        ->and($mailbox->job_title)->toBe('System Engineer')
        ->and($mailbox->employee_type)->toBe('standard')
        ->and($result['differs']['job_title'])->toBe(['kept' => 'System Engineer', 'dropped' => 'IT Engineer'])
        ->and(DB::table('attendance_punches')->where('employee_id', $mailbox->id)->count())->toBe(2)
        ->and(DB::table('attendance_days')->where('subject_key', 'emp:'.$mailbox->id)->where('employee_id', $mailbox->id)->count())->toBe(2)
        ->and(DB::table('biotime_employees')->where('employee_id', $mailbox->id)->count())->toBe(1)
        ->and(DB::table('license_assignments')->where('assignable_id', $mailbox->id)->count())->toBe(1)
        ->and((int) $report->fresh()->manager_id)->toBe($mailbox->id)
        ->and(ActivityLog::where('action', 'employee_records_merged')->where('model_id', $mailbox->id)->exists())->toBeTrue();

    $task = AttendanceTask::first();
    expect($task->type)->toBe('rebuild')
        ->and($task->payload)->toBe(['from' => '2026-09-15', 'to' => '2026-09-16', 'employee_ids' => [$mailbox->id]]);
});

it('clears the kept record\'s link to the duplicate and moves accounts linked to the duplicate onto it', function () {
    $current = mergeRecord(['name' => 'Rana Montaser', 'email' => 'rana.montaser@sssegypt.com', 'azure_id' => 'az-716']);
    $old = mergeRecord(['name' => 'Rana Montaser', 'email' => 'rana.montaser@sssegypt.com', 'azure_id' => 'gone-709', 'status' => 'terminated']);
    $second = mergeRecord(['name' => 'Rana Montaser', 'email' => 'rana.montaser@samirgroup.com', 'azure_id' => 'az-717', 'linked_primary_employee_id' => $old->id]);
    $current->update(['linked_primary_employee_id' => $old->id]);

    [$keep, $duplicates] = EmployeeMerger::order([$old, $current]);
    $result = (new EmployeeMerger)->merge($keep, $duplicates[0]);

    expect($result['merged'])->toBeTrue()
        ->and($keep->id)->toBe($current->id)
        ->and($current->fresh()->linked_primary_employee_id)->toBeNull()
        ->and($second->fresh()->linked_primary_employee_id)->toBe($current->id)
        ->and($current->fresh()->status)->toBe('active');
});

it('drops the duplicate\'s copy of an app account the kept record already has', function () {
    $mailbox = mergeRecord(['name' => 'Bander Alharbi', 'email' => 'bander.alharbi@samirgroup.com', 'azure_id' => 'az-162']);
    $service = mergeRecord(['name' => 'Bander Al-Harbi', 'employee_type' => 'service', 'oracle_emp_no' => '1641']);
    DB::table('employee_app_accounts')->insert([
        ['employee_id' => $mailbox->id, 'business_app_id' => 1],
        ['employee_id' => $service->id, 'business_app_id' => 1],
        ['employee_id' => $service->id, 'business_app_id' => 2],
    ]);

    (new EmployeeMerger)->merge($mailbox, $service);

    expect(DB::table('employee_app_accounts')->where('employee_id', $mailbox->id)->orderBy('business_app_id')->pluck('business_app_id')->all())->toBe([1, 2]);
});

it('refuses merges that would join two people or lose attendance', function () {
    $merger = new EmployeeMerger;

    $a = mergeRecord(['name' => 'Ahmed Salem', 'email' => 'ahmed.salem@samirgroup.com', 'azure_id' => 'az-94']);
    $b = mergeRecord(['name' => 'Ahmed Salem', 'email' => 'ahmed.salem@sssegypt.com', 'azure_id' => 'az-95', 'oracle_emp_no' => '519']);
    expect($merger->merge($b, $a)['problems'])->toContain('Ahmed Salem (#'.$b->id.') and Ahmed Salem (#'.$a->id.') each have a Microsoft account: link them instead.');

    $c = mergeRecord(['name' => 'Mohammed Qasim', 'oracle_emp_no' => '2563']);
    $d = mergeRecord(['name' => 'Mohammed Qasim', 'employee_type' => 'service', 'oracle_emp_no' => '1081']);
    expect($merger->merge($c, $d)['problems'][0])->toContain('two people');

    $e = mergeRecord(['name' => 'Sara Gad', 'email' => 'sara.gad@samirgroup.com']);
    $f = mergeRecord(['name' => 'Sara Gad', 'email' => 'sara.gad@sssegypt.com']);
    expect($merger->merge($e, $f)['problems'][0])->toContain('different email addresses');

    $g = mergeRecord(['name' => 'Yishak Abdurehman', 'email' => 'yishak@samirgroup.com', 'azure_id' => 'az-667']);
    $h = mergeRecord(['name' => 'Yishak Abdurehman', 'employee_type' => 'service', 'oracle_emp_no' => '1472']);
    DB::table('attendance_days')->insert([
        ['subject_key' => 'emp:'.$g->id, 'employee_id' => $g->id, 'work_date' => '2026-09-16'],
        ['subject_key' => 'emp:'.$h->id, 'employee_id' => $h->id, 'work_date' => '2026-09-16'],
    ]);
    $result = $merger->merge($g, $h);
    expect($result['merged'])->toBeFalse()
        ->and($result['problems'][0])->toContain('attendance on the same 1 day')
        ->and(Employee::find($h->id))->not->toBeNull();
});

it('knows every table and column that points at employees', function () {
    // Columns named like employee references that point elsewhere.
    $elsewhere = ['biotime_employee_id', 'vacation_employee_id'];
    $found = [];
    foreach (glob(database_path('migrations/*.php')) as $file) {
        $source = file_get_contents($file);
        preg_match_all("/Schema::(?:create|table)\\('([a-z0-9_]+)'/", $source, $blocks, PREG_OFFSET_CAPTURE);
        foreach ($blocks[1] as $i => [$table, $offset]) {
            $end = $blocks[1][$i + 1][1] ?? strlen($source);
            $body = substr($source, $offset, $end - $offset);
            preg_match_all("/(?:foreignId|unsignedBigInteger|unsignedInteger|bigInteger|integer|foreign)\\('((?:[a-z_]*_)?employee_id|manager_id|supervisor_id)'\\)/", $body, $columns);
            foreach ($columns[1] as $column) {
                if (! in_array($column, $elsewhere, true)) {
                    $found["{$table}.{$column}"] = true;
                }
            }
        }
    }

    $known = [];
    foreach (EmployeeMerger::REFERENCES as $table => $columns) {
        foreach ($columns as $column) {
            $known["{$table}.{$column}"] = true;
        }
    }

    expect(array_keys(array_diff_key($found, $known)))->toBe([]);
});
