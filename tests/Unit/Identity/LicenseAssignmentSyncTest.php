<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\IdentityLicense;
use App\Models\License;
use App\Models\LicenseAssignment;
use App\Services\Identity\GraphService;
use App\Services\Identity\LicenseAssignmentSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Unit\Rbac\RbacTestSchema;

/**
 * The Microsoft → NOC licence sync against real tables and a fake Graph:
 * assignments and seat counts follow Microsoft, --check writes nothing, and a
 * suspiciously short read from Graph never wipes the NOC's records.
 */
uses(Tests\TestCase::class);

const SYNC_SKU_E1 = '18181a46-0d4e-45cd-891e-60aabd171b4e';
const SYNC_SKU_EXCHANGE = '4b9405b0-7788-4568-add1-99614e613b69';

beforeEach(function () {
    RbacTestSchema::create();

    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('azure_id', 36)->nullable();
        $table->string('employee_type')->default('standard');
        $table->string('oracle_emp_no')->nullable();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
    });
    Schema::create('licenses', function (Blueprint $table) {
        $table->id();
        $table->string('license_name');
        $table->string('vendor')->nullable();
        $table->string('license_type')->default('subscription');
        $table->unsignedInteger('seats')->default(1);
        $table->decimal('cost', 15, 2)->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();
    });
    Schema::create('identity_licenses', function (Blueprint $table) {
        $table->id();
        $table->string('sku_id', 36)->unique();
        $table->string('sku_part_number');
        $table->string('display_name');
        $table->unsignedInteger('total')->default(0);
        $table->unsignedInteger('consumed')->default(0);
        $table->unsignedInteger('available')->default(0);
        $table->string('applies_to')->nullable();
        $table->string('capability_status')->default('Enabled');
        $table->unsignedBigInteger('license_id')->nullable();
        $table->timestamps();
    });
    Schema::create('license_assignments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('license_id');
        $table->string('assignable_type');
        $table->unsignedBigInteger('assignable_id');
        $table->date('assigned_date');
        $table->text('notes')->nullable();
        $table->timestamps();
    });
    Schema::create('identity_users', function (Blueprint $table) {
        $table->id();
        $table->string('azure_id', 36)->unique();
        $table->string('display_name')->nullable();
        $table->string('user_principal_name')->nullable();
        $table->string('mail')->nullable();
        $table->boolean('account_enabled')->default(true);
        $table->unsignedInteger('licenses_count')->default(0);
        $table->json('assigned_licenses')->nullable();
        $table->timestamps();
    });

    $this->e1 = License::create(['license_name' => 'Office 365 E1', 'vendor' => 'Microsoft', 'seats' => 233]);
    IdentityLicense::create(['sku_id' => SYNC_SKU_E1, 'sku_part_number' => 'STANDARDPACK', 'display_name' => 'Office 365 E1', 'license_id' => $this->e1->id]);

    $this->sara = syncEmployee('Sara', 'az-sara', 'sara@samirgroup.com');
    $this->omar = syncEmployee('Omar', 'az-omar', 'omar@samirgroup.com');
});

afterEach(function () {
    foreach (['identity_users', 'license_assignments', 'identity_licenses', 'licenses', 'employees'] as $table) {
        Schema::dropIfExists($table);
    }
    RbacTestSchema::drop();
});

function syncEmployee(string $name, ?string $azureId, ?string $email, string $status = 'active'): Employee
{
    // Without events: EmployeeObserver reaches into tables these tests do not build.
    return Employee::withoutEvents(fn () => Employee::create([
        'name' => $name, 'azure_id' => $azureId, 'email' => $email, 'status' => $status,
    ]));
}

function fakeGraph(array $skus, array $users): GraphService
{
    return new class($skus, $users) extends GraphService
    {
        // No parent constructor: it reads Graph credentials from the settings table.
        public function __construct(private array $fakeSkus, private array $fakeUsers) {}

        public function listSubscribedSkus(): array
        {
            return $this->fakeSkus;
        }

        public function listUsersForLicenses(): array
        {
            return $this->fakeUsers;
        }
    };
}

function sku(string $id, string $part, int $enabled, int $consumed = 0): array
{
    return ['skuId' => $id, 'skuPartNumber' => $part, 'appliesTo' => 'User', 'capabilityStatus' => 'Enabled',
        'consumedUnits' => $consumed, 'prepaidUnits' => ['enabled' => $enabled, 'suspended' => 0, 'warning' => 0]];
}

function graphUser(string $id, string $upn, array $skus): array
{
    return ['id' => $id, 'userPrincipalName' => $upn, 'mail' => $upn, 'accountEnabled' => true,
        'assignedLicenses' => array_map(fn ($s) => ['skuId' => $s, 'disabledPlans' => []], $skus), 'licenseAssignmentStates' => []];
}

function assignE1(Employee $employee, License $license, string $notes): LicenseAssignment
{
    return LicenseAssignment::create(['license_id' => $license->id, 'assignable_type' => Employee::class,
        'assignable_id' => $employee->id, 'assigned_date' => '2026-01-01', 'notes' => $notes]);
}

it('copies assignments and seat counts from Microsoft and removes what Microsoft dropped', function () {
    // Omar's E1 was granted from the NOC, then removed in the admin centre.
    assignE1($this->omar, $this->e1, 'Auto-assigned via Graph API');

    $graph = fakeGraph(
        [sku(SYNC_SKU_E1, 'STANDARDPACK', 238, 1)],
        [graphUser('az-sara', 'sara@samirgroup.com', [SYNC_SKU_E1]), graphUser('az-omar', 'omar@samirgroup.com', [])],
    );

    $result = (new LicenseAssignmentSync($graph))->run();

    expect($result['added'])->toBe(1)
        ->and($result['removed'])->toBe(1)
        ->and(LicenseAssignment::pluck('assignable_id')->all())->toBe([$this->sara->id])
        ->and(LicenseAssignment::first()->notes)->toBe('Auto-synced from Azure')
        ->and($this->e1->fresh()->seats)->toBe(238)
        ->and(ActivityLog::where('model_type', 'System')->where('action', 'Microsoft licence sync')->exists())->toBeTrue();
});

it('writes nothing in check mode', function () {
    assignE1($this->omar, $this->e1, 'Auto-synced from Azure');

    $graph = fakeGraph(
        [sku(SYNC_SKU_E1, 'STANDARDPACK', 238)],
        [graphUser('az-sara', 'sara@samirgroup.com', [SYNC_SKU_E1]), graphUser('az-omar', 'omar@samirgroup.com', [])],
    );

    $result = (new LicenseAssignmentSync($graph))->run(apply: false);

    expect($result['plan']->add)->toHaveCount(1)
        ->and($result['plan']->remove)->toHaveCount(1)
        ->and($result['seats'])->toBe([['license_id' => $this->e1->id, 'name' => 'Office 365 E1', 'from' => 233, 'to' => 238]])
        ->and(LicenseAssignment::pluck('assignable_id')->all())->toBe([$this->omar->id])
        ->and($this->e1->fresh()->seats)->toBe(233);
});

it('creates the NOC licence for a newly bought SKU and records its holders in the same run', function () {
    $graph = fakeGraph(
        [sku(SYNC_SKU_E1, 'STANDARDPACK', 233), sku(SYNC_SKU_EXCHANGE, 'EXCHANGESTANDARD', 44, 1)],
        [graphUser('az-sara', 'sara@samirgroup.com', [SYNC_SKU_EXCHANGE]), graphUser('az-omar', 'omar@samirgroup.com', [])],
    );

    (new LicenseAssignmentSync($graph))->run();

    $exchange = IdentityLicense::where('sku_id', SYNC_SKU_EXCHANGE)->first()->itamLicense;
    expect($exchange->seats)->toBe(44)
        ->and(LicenseAssignment::where('license_id', $exchange->id)->pluck('assignable_id')->all())->toBe([$this->sara->id]);
});

it('refuses to sync from a user list far shorter than the NOC knows', function () {
    foreach (range(1, 10) as $n) {
        DB::table('identity_users')->insert(['azure_id' => "az-{$n}", 'user_principal_name' => "u{$n}@samirgroup.com"]);
    }
    assignE1($this->omar, $this->e1, 'Auto-synced from Azure');

    $graph = fakeGraph([sku(SYNC_SKU_E1, 'STANDARDPACK', 233)], [graphUser('az-sara', 'sara@samirgroup.com', [])]);

    expect(fn () => (new LicenseAssignmentSync($graph))->run())->toThrow(RuntimeException::class);
    expect(LicenseAssignment::count())->toBe(1);
});

it('refuses mass removals but still records new assignments', function () {
    foreach (range(1, 30) as $n) {
        assignE1(syncEmployee("Person {$n}", "az-p{$n}", "p{$n}@samirgroup.com"), $this->e1, 'Auto-synced from Azure');
    }

    // Graph lists everyone, but nobody holds E1 any more except Sara: 30 removals of 30 rows.
    $users = [graphUser('az-sara', 'sara@samirgroup.com', [SYNC_SKU_E1])];
    foreach (range(1, 30) as $n) {
        $users[] = graphUser("az-p{$n}", "p{$n}@samirgroup.com", []);
    }

    $result = (new LicenseAssignmentSync(fakeGraph([sku(SYNC_SKU_E1, 'STANDARDPACK', 233)], $users)))->run();

    expect($result['removals_refused'])->toBeTrue()
        ->and($result['removed'])->toBe(0)
        ->and($result['added'])->toBe(1)
        ->and(LicenseAssignment::count())->toBe(31);
});

it('keeps the identity_users licence columns as fresh as the run', function () {
    DB::table('identity_users')->insert(['azure_id' => 'az-sara', 'user_principal_name' => 'sara@samirgroup.com', 'assigned_licenses' => '[]', 'licenses_count' => 0]);

    $graph = fakeGraph([sku(SYNC_SKU_E1, 'STANDARDPACK', 233)], [graphUser('az-sara', 'sara@samirgroup.com', [SYNC_SKU_E1])]);
    (new LicenseAssignmentSync($graph))->run();

    $row = DB::table('identity_users')->where('azure_id', 'az-sara')->first();
    expect(json_decode($row->assigned_licenses, true))->toBe([SYNC_SKU_E1])
        ->and((int) $row->licenses_count)->toBe(1);
});
