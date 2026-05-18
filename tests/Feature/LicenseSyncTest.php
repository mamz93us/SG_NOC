<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\IdentityLicense;
use App\Models\IdentityUser;
use App\Models\License;
use App\Models\LicenseAssignment;
use App\Services\Identity\IdentitySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::create(['id' => 1, 'name' => 'HQ']);
});

it('writes a LicenseAssignment for each Azure-assigned SKU per matched employee', function () {
    // Tenant SKU + ITAM License (manually paired)
    $itamLicense = License::create([
        'license_name' => 'ENTERPRISEPACK',
        'license_type' => 'subscription',
        'seats' => 50,
    ]);
    IdentityLicense::create([
        'sku_id' => 'sku-e3-guid',
        'sku_part_number' => 'ENTERPRISEPACK',
        'display_name' => 'ENTERPRISEPACK',
        'total' => 50,
        'consumed' => 1,
        'available' => 49,
        'license_id' => $itamLicense->id,
    ]);

    $employee = Employee::create([
        'name' => 'Alice',
        'email' => 'alice@company.com',
        'azure_id' => 'azure-aaa',
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    IdentityUser::create([
        'azure_id' => 'azure-aaa',
        'user_principal_name' => 'alice@company.com',
        'mail' => 'alice@company.com',
        'display_name' => 'Alice',
        'account_enabled' => true,
        'assigned_licenses' => ['sku-e3-guid'],
    ]);

    $errors = [];
    (new IdentitySyncService)->syncEmployeeLicenseAssignments($errors);

    $assignment = LicenseAssignment::where('license_id', $itamLicense->id)
        ->where('assignable_type', Employee::class)
        ->where('assignable_id', $employee->id)
        ->first();

    expect($assignment)->not()->toBeNull();
    expect($assignment->notes)->toBe('Auto-synced from Azure');
});

it('removes auto-synced LicenseAssignments when Azure drops the SKU', function () {
    $itamLicense = License::create([
        'license_name' => 'OLD_SKU',
        'license_type' => 'subscription',
        'seats' => 10,
    ]);
    IdentityLicense::create([
        'sku_id' => 'sku-old',
        'sku_part_number' => 'OLD_SKU',
        'display_name' => 'OLD_SKU',
        'total' => 10,
        'consumed' => 0,
        'available' => 10,
        'license_id' => $itamLicense->id,
    ]);

    $employee = Employee::create([
        'name' => 'Bob',
        'email' => 'bob@company.com',
        'azure_id' => 'azure-bbb',
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    IdentityUser::create([
        'azure_id' => 'azure-bbb',
        'user_principal_name' => 'bob@company.com',
        'mail' => 'bob@company.com',
        'display_name' => 'Bob',
        'account_enabled' => true,
        'assigned_licenses' => [], // Bob has nothing assigned now
    ]);

    // Pre-existing auto-synced assignment that should get cleaned up
    LicenseAssignment::create([
        'license_id' => $itamLicense->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
        'assigned_date' => now()->subMonth(),
        'notes' => 'Auto-synced from Azure',
    ]);

    $errors = [];
    (new IdentitySyncService)->syncEmployeeLicenseAssignments($errors);

    expect(LicenseAssignment::where('assignable_id', $employee->id)->count())->toBe(0);
});

it('preserves manually-created LicenseAssignments (different notes) when Azure drops the SKU', function () {
    $itamLicense = License::create([
        'license_name' => 'CRM',
        'license_type' => 'subscription',
        'seats' => 5,
    ]);
    IdentityLicense::create([
        'sku_id' => 'sku-crm',
        'sku_part_number' => 'CRM',
        'display_name' => 'CRM',
        'total' => 5,
        'consumed' => 0,
        'available' => 5,
        'license_id' => $itamLicense->id,
    ]);

    $employee = Employee::create([
        'name' => 'Carol',
        'email' => 'carol@company.com',
        'azure_id' => 'azure-ccc',
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    IdentityUser::create([
        'azure_id' => 'azure-ccc',
        'user_principal_name' => 'carol@company.com',
        'display_name' => 'Carol',
        'account_enabled' => true,
        'assigned_licenses' => [],
    ]);

    LicenseAssignment::create([
        'license_id' => $itamLicense->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
        'assigned_date' => now(),
        'notes' => 'Assigned manually by admin', // ← not Azure-synced
    ]);

    $errors = [];
    (new IdentitySyncService)->syncEmployeeLicenseAssignments($errors);

    expect(LicenseAssignment::where('assignable_id', $employee->id)->count())->toBe(1);
});
