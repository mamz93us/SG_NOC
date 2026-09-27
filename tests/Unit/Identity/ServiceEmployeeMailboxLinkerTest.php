<?php

use App\Models\Employee;
use App\Models\IdentityUser;
use App\Services\Identity\ServiceEmployeeMailboxLinker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giving a service employee the mailbox they turn out to have.
 *
 * The guards carry the weight here. `employees.email` has no unique index and
 * no unique validation rule, so nothing in the database stops one address
 * landing on two people — which is the exact mistake the Oracle HR import
 * refuses to make when it leaves a service employee's email null. Every refusal
 * below is the only thing standing in that place.
 *
 * Built by hand without RefreshDatabase: several migrations in this repo are
 * MySQL-only and cannot run on SQLite.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['activity_logs', 'identity_users', 'employees', 'branches', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });
    Schema::create('branches', function (Blueprint $t) {
        $t->unsignedInteger('id')->primary();
        $t->string('name');
        $t->timestamps();
    });
    Schema::create('activity_logs', function (Blueprint $t) {
        $t->id();
        $t->string('model_type')->nullable();
        $t->unsignedBigInteger('model_id')->nullable();
        $t->string('model_label')->nullable();
        $t->string('action')->nullable();
        $t->json('changes')->nullable();
        $t->string('actor_label')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('ip_address')->nullable();
        $t->string('user_agent')->nullable();
        $t->timestamps();
    });
    Schema::create('identity_users', function (Blueprint $t) {
        $t->id();
        $t->string('azure_id')->nullable();
        $t->string('display_name')->nullable();
        $t->string('user_principal_name')->nullable();
        $t->string('mail')->nullable();
        $t->string('job_title')->nullable();
        $t->string('department')->nullable();
        $t->boolean('account_enabled')->default(true);
        $t->timestamps();
    });
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('azure_id')->nullable();
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('employee_type', 20)->default(Employee::TYPE_STANDARD);
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedBigInteger('department_id')->nullable();
        $t->unsignedBigInteger('linked_primary_employee_id')->nullable();
        $t->string('job_title')->nullable();
        $t->string('oracle_emp_no')->nullable();
        $t->string('status')->default('active');
        $t->timestamps();
    });
});

function mailboxLinker(): ServiceEmployeeMailboxLinker
{
    return app(ServiceEmployeeMailboxLinker::class);
}

function serviceEmployee(string $name = 'Bander Al-Harbi', array $attributes = []): Employee
{
    return Employee::create(array_merge([
        'name' => $name,
        'email' => null,
        'employee_type' => Employee::TYPE_SERVICE,
        'oracle_emp_no' => '9001',
        'status' => 'active',
        'job_title' => 'Warehouse Assistant',
    ], $attributes));
}

function entraAccount(string $name, string $mail, array $attributes = []): IdentityUser
{
    return IdentityUser::create(array_merge([
        'azure_id' => 'azure-'.md5($mail),
        'display_name' => $name,
        'mail' => $mail,
        'user_principal_name' => $mail,
        'account_enabled' => true,
    ], $attributes));
}

// ─── Who may be given a mailbox ───────────────────────────────────

it('lets a service employee with no mailbox be given one', function () {
    expect(mailboxLinker()->eligible(serviceEmployee()))->toBeTrue();
});

it('refuses somebody who already has an address', function () {
    $employee = serviceEmployee(attributes: ['email' => 'someone@samirgroup.com']);

    expect(mailboxLinker()->eligible($employee))->toBeFalse();
    expect(mailboxLinker()->problemWith($employee))->toContain('already has');
});

it('refuses somebody already linked to a Microsoft account', function () {
    // Re-pointing an existing mailbox is a different and far more dangerous
    // operation than attaching a first one.
    $employee = serviceEmployee(attributes: ['azure_id' => 'azure-existing']);

    expect(mailboxLinker()->problemWith($employee))->toContain('already linked');
});

it('refuses a linked secondary account and points at the main record', function () {
    $primary = serviceEmployee('Main Record', ['oracle_emp_no' => '1']);
    $secondary = serviceEmployee('Second Record', ['oracle_emp_no' => '2', 'linked_primary_employee_id' => $primary->id]);

    expect(mailboxLinker()->problemWith($secondary))->toContain('main record');
});

it('refuses somebody who has left', function () {
    expect(mailboxLinker()->problemWith(serviceEmployee(attributes: ['status' => 'terminated'])))
        ->toContain('left the company');
});

// ─── Linking an Entra account ─────────────────────────────────────

it('puts the address and the account id on the record and stops it being a service record', function () {
    $employee = serviceEmployee();
    $account = entraAccount('Bander Al-Harbi', 'b.alharbi@samirgroup.com');

    $result = mailboxLinker()->linkEntra($employee, $account->azure_id);

    expect($result['ok'])->toBeTrue();

    $employee->refresh();

    expect($employee->email)->toBe('b.alharbi@samirgroup.com')
        ->and($employee->azure_id)->toBe($account->azure_id)
        // The column means "holds a mailbox"; leaving it as service would lie.
        ->and($employee->employee_type)->toBe(Employee::TYPE_STANDARD);
});

it('refuses an address another employee already holds', function () {
    // There is no unique index on employees.email, so this check is the only
    // thing stopping two people owning one mailbox.
    Employee::create(['name' => 'Someone Else', 'email' => 'b.alharbi@samirgroup.com', 'status' => 'active']);

    $employee = serviceEmployee();
    $account = entraAccount('Bander Al-Harbi', 'b.alharbi@samirgroup.com');

    $result = mailboxLinker()->linkEntra($employee, $account->azure_id);

    expect($result['ok'])->toBeFalse()
        ->and($result['problems'][0])->toContain('Someone Else')
        ->and($employee->refresh()->email)->toBeNull()
        ->and($employee->employee_type)->toBe(Employee::TYPE_SERVICE);
});

it('matches a held address regardless of case', function () {
    Employee::create(['name' => 'Someone Else', 'email' => 'B.AlHarbi@SamirGroup.com', 'status' => 'active']);

    $account = entraAccount('Bander Al-Harbi', 'b.alharbi@samirgroup.com');

    expect(mailboxLinker()->linkEntra(serviceEmployee(), $account->azure_id)['ok'])->toBeFalse();
});

it('refuses an account another employee already holds', function () {
    $account = entraAccount('Bander Al-Harbi', 'b.alharbi@samirgroup.com');
    Employee::create(['name' => 'Someone Else', 'azure_id' => $account->azure_id, 'status' => 'active']);

    $result = mailboxLinker()->linkEntra(serviceEmployee(), $account->azure_id);

    expect($result['ok'])->toBeFalse()
        ->and($result['problems'][0])->toContain('Microsoft account');
});

it('refuses an account the NOC no longer holds', function () {
    $result = mailboxLinker()->linkEntra(serviceEmployee(), 'azure-never-synced');

    expect($result['ok'])->toBeFalse()
        ->and($result['problems'][0])->toContain('no longer in the NOC');
});

it('refuses an account with no address at all', function () {
    $account = IdentityUser::create([
        'azure_id' => 'azure-no-mail', 'display_name' => 'A Room', 'account_enabled' => true,
    ]);

    expect(mailboxLinker()->linkEntra(serviceEmployee(), $account->azure_id)['ok'])->toBeFalse();
});

it('falls back to the sign-in name when there is no mailbox address', function () {
    $account = IdentityUser::create([
        'azure_id' => 'azure-upn-only', 'display_name' => 'Bander Al-Harbi',
        'user_principal_name' => 'b.alharbi@samirgroup.com', 'account_enabled' => true,
    ]);

    mailboxLinker()->linkEntra(serviceEmployee(), $account->azure_id);

    expect(Employee::where('oracle_emp_no', '9001')->sole()->email)->toBe('b.alharbi@samirgroup.com');
});

it('does not copy the job title from Microsoft over Oracle\'s', function () {
    // Oracle owns this person's HR fields and the import has already written
    // them; the Entra record is usually emptier and sometimes older.
    $employee = serviceEmployee();
    $account = entraAccount('Bander Al-Harbi', 'b.alharbi@samirgroup.com', ['job_title' => 'Something Else']);

    mailboxLinker()->linkEntra($employee, $account->azure_id);

    expect($employee->refresh()->job_title)->toBe('Warehouse Assistant');
});

it('records the link in the audit log without inventing a before state', function () {
    $employee = serviceEmployee();
    $account = entraAccount('Bander Al-Harbi', 'b.alharbi@samirgroup.com');

    mailboxLinker()->linkEntra($employee, $account->azure_id);

    $log = DB::table('activity_logs')->where('action', 'service_employee_mailbox_linked')->sole();
    $changes = json_decode($log->changes, true);

    expect($log->model_id)->toBe($employee->id)
        ->and($changes['after']['email'])->toBe('b.alharbi@samirgroup.com')
        ->and($changes['before']['email'])->toBeNull()
        ->and($changes['oracle_emp_no'])->toBe('9001');
});

// ─── The candidate list ───────────────────────────────────────────

it('offers Entra accounts no employee holds', function () {
    entraAccount('Free Account', 'free@samirgroup.com');
    $taken = entraAccount('Taken Account', 'taken@samirgroup.com');
    Employee::create(['name' => 'Holder', 'email' => 'taken@samirgroup.com', 'azure_id' => $taken->azure_id, 'status' => 'active']);

    $emails = array_column(mailboxLinker()->candidates(serviceEmployee(), 'account'), 'email');

    expect($emails)->toContain('free@samirgroup.com')
        ->and($emails)->not->toContain('taken@samirgroup.com');
});

it('puts a same-name account first and marks it', function () {
    entraAccount('Zzz Someone', 'zzz@samirgroup.com');
    entraAccount('Bander Alharbi', 'b.alharbi@samirgroup.com');

    $candidates = mailboxLinker()->candidates(serviceEmployee('Bander Al-Harbi'));

    // "Bander Al-Harbi" and "Bander Alharbi" normalise to one name.
    expect($candidates[0]['email'])->toBe('b.alharbi@samirgroup.com')
        ->and($candidates[0]['suggested'])->toBeTrue();
});

it('offers nothing at all until something is typed, when no name matches', function () {
    // The alternative is the first 25 accounts in the directory by name, which
    // look like candidates and are not. For these people "there is nothing to
    // suggest" is usually the true answer: measured on production, one of 77
    // service employees has a same-name account.
    entraAccount('Someone Unrelated', 'unrelated@samirgroup.com');
    entraAccount('Another Person', 'another@samirgroup.com');

    expect(mailboxLinker()->candidates(serviceEmployee('Bander Al-Harbi')))->toBe([]);

    // Typing finds them.
    expect(mailboxLinker()->candidates(serviceEmployee('Bander Al-Harbi'), 'unrelated'))->toHaveCount(1);
});

it('does not offer every mailbox in the company when nothing is typed', function () {
    // 727 addresses in a picker is a list, not a choice. With no search, an
    // employee record is only offered when it looks like the same person.
    foreach (range(1, 12) as $i) {
        Employee::create(['name' => "Unrelated Person {$i}", 'email' => "p{$i}@samirgroup.com", 'status' => 'active']);
    }

    $candidates = mailboxLinker()->candidates(serviceEmployee('Bander Al-Harbi'));
    $employeeKind = array_filter($candidates, fn ($c) => $c['kind'] === ServiceEmployeeMailboxLinker::KIND_EMPLOYEE);

    expect($employeeKind)->toBeEmpty();
});

it('offers the employee record that holds the same name, as a merge', function () {
    Employee::create(['name' => 'Bander Alharbi', 'email' => 'b.alharbi@samirgroup.com', 'status' => 'active']);

    $candidates = mailboxLinker()->candidates(serviceEmployee('Bander Al-Harbi'));
    $match = collect($candidates)->firstWhere('email', 'b.alharbi@samirgroup.com');

    expect($match['kind'])->toBe(ServiceEmployeeMailboxLinker::KIND_EMPLOYEE)
        ->and($match['note'])->toContain('merges');
});

it('finds an employee record by address once something is typed', function () {
    Employee::create(['name' => 'Totally Different Name', 'email' => 'b.alharbi@samirgroup.com', 'status' => 'active']);

    $emails = array_column(mailboxLinker()->candidates(serviceEmployee('Bander Al-Harbi'), 'b.alharbi'), 'email');

    expect($emails)->toContain('b.alharbi@samirgroup.com');
});

it('never offers the employee themselves', function () {
    $employee = serviceEmployee('Bander Al-Harbi', ['email' => null]);

    $refs = array_column(
        array_filter(mailboxLinker()->candidates($employee, 'Bander'), fn ($c) => $c['kind'] === 'employee'),
        'ref'
    );

    expect($refs)->not->toContain((string) $employee->id);
});

it('never offers a linked secondary record', function () {
    $primary = Employee::create(['name' => 'Main One', 'email' => 'main@samirgroup.com', 'status' => 'active']);
    Employee::create(['name' => 'Bander Alharbi', 'email' => 'second@samirgroup.com',
        'status' => 'active', 'linked_primary_employee_id' => $primary->id]);

    $emails = array_column(mailboxLinker()->candidates(serviceEmployee('Bander Al-Harbi'), 'second'), 'email');

    expect($emails)->not->toContain('second@samirgroup.com');
});

// ─── The merge path ───────────────────────────────────────────────

it('refuses to merge a record with itself', function () {
    $employee = serviceEmployee();

    expect(mailboxLinker()->mergeWithEmployee($employee, $employee)['ok'])->toBeFalse();
});

it('refuses to merge when the employee is not eligible in the first place', function () {
    $employee = serviceEmployee(attributes: ['email' => 'already@samirgroup.com']);
    $other = Employee::create(['name' => 'Other', 'email' => 'other@samirgroup.com', 'status' => 'active']);

    $result = mailboxLinker()->mergeWithEmployee($employee, $other);

    expect($result['ok'])->toBeFalse()
        ->and($result['problems'][0])->toContain('already has');
});
