<?php

use App\Models\AzureDevice;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Services\AzureDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::create(['id' => 1, 'name' => 'HQ']);

    $this->alice = Employee::create([
        'name' => 'Alice',
        'email' => 'alice@company.com',
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    $this->device = Device::create([
        'name' => 'ALICE-PC',
        'type' => 'laptop',
        'serial_number' => 'SN-RECYCLED',
        'branch_id' => $this->branch->id,
        'status' => 'assigned',
    ]);

    $this->existingAzure = AzureDevice::create([
        'azure_device_id' => 'old-azure-id-AAA',
        'display_name' => 'ALICE-PC',
        'serial_number' => 'SN-RECYCLED',
        'upn' => 'alice@company.com',
        'device_id' => $this->device->id,
        'link_status' => 'linked',
        'last_sync_at' => now()->subDays(5),
    ]);

    EmployeeAsset::create([
        'employee_id' => $this->alice->id,
        'asset_id' => $this->device->id,
        'assigned_date' => now()->subDays(30),
    ]);
});

it('merges into existing row when serial matches but azure_device_id has rotated (same UPN)', function () {
    $service = new AzureDeviceService;

    $result = $service->upsertDevice([
        'azure_device_id' => 'new-azure-id-BBB',
        'display_name' => 'ALICE-PC',
        'serial_number' => 'SN-RECYCLED',
        'upn' => 'alice@company.com',
        'device_type' => 'laptop',
    ]);

    expect(AzureDevice::count())->toBe(1);

    $merged = AzureDevice::first();
    expect($merged->id)->toBe($this->existingAzure->id);
    expect($merged->azure_device_id)->toBe('new-azure-id-BBB');
    expect($merged->device_id)->toBe($this->device->id);

    // Same UPN — no employee_assets disruption
    expect(EmployeeAsset::whereNull('returned_date')->count())->toBe(1);

    expect($result['new'])->toBeFalse();
});

it('soft-returns the old EmployeeAsset row when UPN changes on serial rotation', function () {
    $bob = Employee::create([
        'name' => 'Bob',
        'email' => 'bob@company.com',
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    $service = new AzureDeviceService;

    $service->upsertDevice([
        'azure_device_id' => 'new-azure-id-BBB',
        'display_name' => 'BOB-PC',
        'serial_number' => 'SN-RECYCLED',
        'upn' => 'bob@company.com', // ← different UPN
        'device_type' => 'laptop',
    ]);

    // Old assignment soft-returned
    $oldAssignment = EmployeeAsset::where('employee_id', $this->alice->id)->first();
    expect($oldAssignment->returned_date)->not()->toBeNull();
    expect($oldAssignment->notes)->toContain('Azure device rotated');

    // New active assignment created via attemptAutoAssign for Bob
    $newAssignment = EmployeeAsset::where('employee_id', $bob->id)
        ->whereNull('returned_date')
        ->first();
    expect($newAssignment)->not()->toBeNull();
});

it('creates a new AzureDevice row when serial does not match any existing row', function () {
    $service = new AzureDeviceService;

    $service->upsertDevice([
        'azure_device_id' => 'totally-new-CCC',
        'display_name' => 'NEW-PC',
        'serial_number' => 'SN-BRAND-NEW',
        'upn' => 'carol@company.com',
    ]);

    expect(AzureDevice::count())->toBe(2);
    expect(AzureDevice::where('azure_device_id', 'totally-new-CCC')->exists())->toBeTrue();
});

it('updates in place when azure_device_id matches exactly (the happy path, not the dedup path)', function () {
    $service = new AzureDeviceService;

    $service->upsertDevice([
        'azure_device_id' => 'old-azure-id-AAA',
        'display_name' => 'ALICE-PC-RENAMED',
        'serial_number' => 'SN-RECYCLED',
        'upn' => 'alice@company.com',
    ]);

    expect(AzureDevice::count())->toBe(1);
    expect(AzureDevice::first()->display_name)->toBe('ALICE-PC-RENAMED');
});
