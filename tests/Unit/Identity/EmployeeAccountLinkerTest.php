<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Services\Identity\EmployeeAccountLinker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Unit\Rbac\RbacTestSchema;

/**
 * Linking a person's records: the link is made, the main record gains what it
 * was missing and loses nothing, and the links that would split a person are
 * refused.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    RbacTestSchema::create();

    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('azure_id', 36)->nullable();
        $table->string('employee_type')->default('standard');
        $table->string('oracle_emp_no')->nullable();
        $table->string('oracle_department')->nullable();
        $table->string('name');
        $table->string('gender')->nullable();
        $table->string('email')->nullable();
        $table->unsignedBigInteger('branch_id')->nullable();
        $table->unsignedBigInteger('department_id')->nullable();
        $table->unsignedBigInteger('manager_id')->nullable();
        $table->unsignedBigInteger('supervisor_id')->nullable();
        $table->string('job_title')->nullable();
        $table->string('status')->default('active');
        $table->string('mobile_phone')->nullable();
        $table->string('extension_number')->nullable();
        $table->unsignedBigInteger('ucm_server_id')->nullable();
        $table->unsignedBigInteger('linked_primary_employee_id')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('employees');
    RbacTestSchema::drop();
});

function linkRecord(array $attributes): Employee
{
    // Without events: EmployeeObserver reconciles marketing lists on create, which needs tables these tests do not build.
    return Employee::withoutEvents(fn () => Employee::create(array_merge(['status' => 'active'], $attributes)));
}

it('links a mailbox record to the service record from the HR import and fills what the main record was missing', function () {
    $service = linkRecord(['name' => 'Ibrahim Syed', 'employee_type' => 'service', 'oracle_emp_no' => '2693', 'job_title' => 'Applications Specialist']);
    $mailbox = linkRecord([
        'name' => 'Ibrahim Syed', 'email' => 'ibrahim.syed@samirgroup.com', 'azure_id' => 'az-285',
        'job_title' => 'System Engineer', 'mobile_phone' => '+966500000001', 'extension_number' => '4410', 'manager_id' => 77,
    ]);

    $result = (new EmployeeAccountLinker)->link($service, [$mailbox->id]);

    $service->refresh();
    expect($result['linked'])->toBe(['ibrahim.syed@samirgroup.com'])
        ->and($mailbox->fresh()->linked_primary_employee_id)->toBe($service->id)
        ->and($service->job_title)->toBe('Applications Specialist')
        ->and($service->mobile_phone)->toBe('+966500000001')
        ->and($service->extension_number)->toBe('4410')
        ->and((int) $service->manager_id)->toBe(77)
        ->and(ActivityLog::where('action', 'linked_account_set')->where('model_id', $mailbox->id)->exists())->toBeTrue();
});

it('does not make a record its own manager when the linked account reports to it', function () {
    $main = linkRecord(['name' => 'Saher Al-Hindi', 'email' => 'saher.alhindi@samirgroup.com', 'oracle_emp_no' => '1656']);
    $other = linkRecord(['name' => 'Saher Alhindi', 'email' => 'saher@sssegypt.com', 'manager_id' => $main->id]);

    (new EmployeeAccountLinker)->link($main, [$other->id]);

    expect($main->fresh()->manager_id)->toBeNull();
});

it('refuses links that would split a person or join the wrong records', function () {
    $main = linkRecord(['name' => 'Sara Gad', 'email' => 'sara.gad@sssegypt.com', 'oracle_emp_no' => '523']);
    $elsewhere = linkRecord(['name' => 'Someone', 'email' => 'someone@samirgroup.com']);
    $linkedElsewhere = linkRecord(['name' => 'Sara Gad', 'email' => 'sara.gad@oriana-sa.com', 'linked_primary_employee_id' => $elsewhere->id]);
    $left = linkRecord(['name' => 'Sara Gad', 'email' => 'sara.gad@samirgroup.com', 'status' => 'terminated']);

    $result = (new EmployeeAccountLinker)->link($main, [$main->id, $linkedElsewhere->id, $left->id, 99999]);

    expect($result['linked'])->toBe([])
        ->and($result['skipped'])->toHaveCount(4)
        ->and($linkedElsewhere->fresh()->linked_primary_employee_id)->toBe($elsewhere->id)
        ->and($left->fresh()->linked_primary_employee_id)->toBeNull();

    $secondary = linkRecord(['name' => 'Sara Gad', 'email' => 'sara@samirgroup.com', 'linked_primary_employee_id' => $main->id]);
    expect((new EmployeeAccountLinker)->link($secondary, [$elsewhere->id])['skipped'][0])->toContain('is itself a linked account');
});

it('moves the accounts already linked to a record onto the main record it is linked to', function () {
    $main = linkRecord(['name' => 'Nada Khier', 'email' => 'nada.khier@sssegypt.com', 'oracle_emp_no' => '520']);
    $middle = linkRecord(['name' => 'Nada Khier', 'email' => 'nada.khier@samirgroup.com']);
    $tail = linkRecord(['name' => 'Nada Khier', 'email' => 'nada@oriana-sa.com', 'linked_primary_employee_id' => $middle->id]);

    (new EmployeeAccountLinker)->link($main, [$middle->id]);

    expect($middle->fresh()->linked_primary_employee_id)->toBe($main->id)
        ->and($tail->fresh()->linked_primary_employee_id)->toBe($main->id);
});
