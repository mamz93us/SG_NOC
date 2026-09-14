<?php

use App\Models\Employee;
use App\Models\IdentityUser;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Identity\EntraUserAccounts;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Unit\Rbac\RbacTestSchema;

/**
 * "Add user ▸ From Entra": who can be added, which address the account is
 * stored under, and never a second account for someone who already has one.
 *
 * The address is the part that bites. MicrosoftController matches a sign-in on
 * userPrincipalName and creates a fresh default-role user when nothing matches,
 * so a role given to an account under any other address is a role its owner
 * never receives.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    RbacTestSchema::create();

    Schema::create('branches', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('azure_id', 36)->nullable();
        $table->string('employee_type')->default('standard');
        $table->string('oracle_emp_no')->nullable();
        $table->string('name');
        $table->string('email')->nullable();
        $table->unsignedBigInteger('branch_id')->nullable();
        $table->unsignedBigInteger('department_id')->nullable();
        $table->string('job_title')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
    });

    Schema::create('identity_users', function (Blueprint $table) {
        $table->id();
        $table->string('azure_id', 36)->unique();
        $table->string('display_name')->nullable();
        $table->string('user_principal_name')->unique();
        $table->string('mail')->nullable();
        $table->boolean('account_enabled')->default(true);
        $table->timestamps();
    });

    Role::clearCache();
    RolePermission::clearCache();
    User::clearOverrideCache();
});

afterEach(function () {
    foreach (['identity_users', 'employees', 'branches'] as $table) {
        Schema::dropIfExists($table);
    }

    RbacTestSchema::drop();
});

/**
 * An employee, and — unless $identity is null — their row in the Entra mirror.
 */
function entraEmployee(array $employee = [], ?array $identity = []): Employee
{
    static $n = 0;
    $n++;

    $azureId = array_key_exists('azure_id', $employee)
        ? $employee['azure_id']
        : sprintf('00000000-0000-0000-0000-%012d', $n);

    // Without events: EmployeeObserver reconciles marketing lists on create,
    // which needs tables these tests have no reason to build.
    return Employee::withoutEvents(function () use ($employee, $identity, $azureId, $n) {
        if ($identity !== null && $azureId) {
            IdentityUser::create(array_merge([
                'azure_id' => $azureId,
                'display_name' => $employee['name'] ?? "Person {$n}",
                'user_principal_name' => "person{$n}@samirgroup.com",
                'mail' => "person{$n}@samirgroup.com",
                'account_enabled' => true,
            ], $identity));
        }

        return Employee::create(array_merge([
            'azure_id' => $azureId,
            'name' => "Person {$n}",
            'email' => "person{$n}@samirgroup.com",
            'status' => 'active',
        ], $employee))->load('identityUser');
    });
}

// ── The address ────────────────────────────────────────────────

it('stores the account under the UPN SSO will present, not the work email', function () {
    $employee = entraEmployee(
        ['name' => 'Sara Adel', 'email' => 'sara.adel@samirgroup.com'],
        ['user_principal_name' => 'sadel@samirgroup.com'],
    );

    $accounts = new EntraUserAccounts;

    expect($accounts->signInEmail($employee))->toBe('sadel@samirgroup.com');

    ['user' => $user, 'previous_role' => $previous] = $accounts->assign($employee, 'hr');

    expect($previous)->toBeNull();
    expect($user->fresh())
        ->email->toBe('sadel@samirgroup.com')
        ->name->toBe('Sara Adel')
        ->role->toBe('hr');
});

it('falls back to the work email for someone the identity sync has not mirrored yet', function () {
    $employee = entraEmployee(['email' => 'new.hire@samirgroup.com'], null);

    $accounts = new EntraUserAccounts;

    expect($accounts->signInEmail($employee))->toBe('new.hire@samirgroup.com');
    expect($accounts->unavailableReason($employee))->toBeNull();
});

// ── Existing accounts ──────────────────────────────────────────

it('changes the role on an existing account instead of creating a second one', function () {
    // The usual case: they signed in once and SSO made them the default role.
    // Entra spells the UPN with capitals; the stored row does not.
    $employee = entraEmployee([], ['user_principal_name' => 'Omar.Nabil@samirgroup.com']);
    User::create(['name' => 'Omar', 'email' => 'omar.nabil@samirgroup.com', 'password' => 'x', 'role' => 'browser_user']);

    ['user' => $user, 'previous_role' => $previous] = (new EntraUserAccounts)->assign($employee, 'hr', '201001234567');

    expect(User::count())->toBe(1);
    expect($previous)->toBe('browser_user');
    expect($user->fresh())
        ->role->toBe('hr')
        ->whatsapp_number->toBe('201001234567');
});

it('keeps an existing WhatsApp number when the form leaves it blank', function () {
    $employee = entraEmployee([], ['user_principal_name' => 'lina@samirgroup.com']);
    User::create([
        'name' => 'Lina', 'email' => 'lina@samirgroup.com', 'password' => 'x',
        'role' => 'viewer', 'whatsapp_number' => '966500000000',
    ]);

    ['user' => $user] = (new EntraUserAccounts)->assign($employee, 'hr', null);

    expect($user->fresh()->whatsapp_number)->toBe('966500000000');
});

it('finds an account stored under the work email when the UPN differs', function () {
    $employee = entraEmployee(['email' => 'mona@samirgroup.com'], ['user_principal_name' => 'mona.k@samirgroup.com']);
    $local = User::create(['name' => 'Mona', 'email' => 'mona@samirgroup.com', 'password' => 'x', 'role' => 'viewer']);

    expect((new EntraUserAccounts)->existingUser($employee)?->id)->toBe($local->id);
});

it('prefers the account under the UPN when both addresses have one', function () {
    $employee = entraEmployee(['email' => 'mona@samirgroup.com'], ['user_principal_name' => 'mona.k@samirgroup.com']);
    User::create(['name' => 'Mona (local)', 'email' => 'mona@samirgroup.com', 'password' => 'x', 'role' => 'viewer']);
    $sso = User::create(['name' => 'Mona', 'email' => 'mona.k@samirgroup.com', 'password' => 'x', 'role' => 'browser_user']);

    expect((new EntraUserAccounts)->existingUser($employee)?->id)->toBe($sso->id);
});

// ── Who can be added ───────────────────────────────────────────

it('refuses people who cannot sign in with Microsoft', function () {
    $accounts = new EntraUserAccounts;

    expect($accounts->unavailableReason(entraEmployee(['azure_id' => null], null)))->toContain('no Entra account');
    expect($accounts->unavailableReason(entraEmployee(['status' => 'terminated'])))->toContain('left the company');
    expect($accounts->unavailableReason(entraEmployee([], ['account_enabled' => false])))->toContain('disabled');
    expect($accounts->unavailableReason(entraEmployee()))->toBeNull();
});

// ── Search ─────────────────────────────────────────────────────

it('lists only employees with an Entra account, flagging who already has one', function () {
    $cairo = DB::table('branches')->insertGetId(['name' => 'Cairo', 'created_at' => now(), 'updated_at' => now()]);

    entraEmployee(
        ['name' => 'Hana Salem', 'branch_id' => $cairo, 'job_title' => 'HR Specialist'],
        ['user_principal_name' => 'hana.salem@samirgroup.com'],
    );
    entraEmployee(['name' => 'Hana Mostafa'], ['account_enabled' => false]);
    entraEmployee(['name' => 'Hana Driver', 'azure_id' => null], null);   // service staff: no Entra account
    entraEmployee(['name' => 'Hana Former', 'status' => 'terminated']);

    User::create(['name' => 'Hana', 'email' => 'hana.salem@samirgroup.com', 'password' => 'x', 'role' => 'browser_user']);

    $rows = collect((new EntraUserAccounts)->search('hana'))->keyBy('name');

    // Disabled accounts are listed with the reason rather than hidden, so the
    // admin can see why someone cannot be added.
    expect($rows->keys()->all())->toBe(['Hana Mostafa', 'Hana Salem']);

    expect($rows['Hana Salem'])->toMatchArray([
        'email' => 'hana.salem@samirgroup.com',
        'branch' => 'Cairo',
        'job_title' => 'HR Specialist',
        'unavailable' => null,
    ]);
    expect($rows['Hana Salem']['user'])->toMatchArray(['role' => 'browser_user', 'role_label' => 'Browser User']);

    expect($rows['Hana Mostafa']['unavailable'])->toContain('disabled');
    expect($rows['Hana Mostafa']['user'])->toBeNull();
});

it('matches on the UPN and the Oracle number as well as the name', function () {
    entraEmployee(['name' => 'Karim Fathy', 'oracle_emp_no' => '55512'], ['user_principal_name' => 'kfathy@samirgroup.com']);
    entraEmployee(['name' => 'Someone Else']);

    $accounts = new EntraUserAccounts;

    expect(collect($accounts->search('kfathy'))->pluck('name')->all())->toBe(['Karim Fathy']);
    expect(collect($accounts->search('55512'))->pluck('name')->all())->toBe(['Karim Fathy']);
});

it('returns nothing for a one-character search', function () {
    entraEmployee(['name' => 'Ali Hassan']);

    expect((new EntraUserAccounts)->search('a'))->toBe([]);
});
