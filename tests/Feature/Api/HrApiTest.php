<?php

use App\Models\AzureDevice;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\HrApiKey;
use App\Models\IdentityUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a real HrApiKey and return [rawKey, model].
 */
function makeApiKey(): array
{
    return HrApiKey::generate('Test Key', 'automated test');
}

/**
 * Return headers with a valid API key.
 */
function apiHeaders(string $rawKey): array
{
    return [
        'X-HR-Api-Key' => $rawKey,
        'Accept'       => 'application/json',
        'Content-Type' => 'application/json',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// A. Authentication — shared across all endpoints
// ─────────────────────────────────────────────────────────────────────────────

describe('HR API Authentication', function () {

    it('returns 401 when no API key is provided', function () {
        $this->getJson('/api/hr/device-lookup?upn=test@test.com')
            ->assertStatus(401)
            ->assertJsonFragment(['error' => 'API key required.']);
    });

    it('returns 401 for an invalid API key', function () {
        $this->getJson('/api/hr/device-lookup?upn=test@test.com', [
            'X-HR-Api-Key' => 'hrk_thisisaninvalidkeyxxxxxxxxxxxxxxxxxxxxxxx',
            'Accept'       => 'application/json',
        ])->assertStatus(401)
          ->assertJsonFragment(['error' => 'Invalid or revoked API key.']);
    });

    it('returns 401 for a revoked API key', function () {
        [$raw, $key] = makeApiKey();
        $key->revoke();

        $this->getJson('/api/hr/device-lookup?upn=test@test.com', [
            'X-HR-Api-Key' => $raw,
            'Accept'       => 'application/json',
        ])->assertStatus(401);
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// B. GET /api/hr/device-lookup
// ─────────────────────────────────────────────────────────────────────────────

describe('GET /api/hr/device-lookup', function () {

    it('returns 422 when upn is missing', function () {
        [$raw] = makeApiKey();

        $this->getJson('/api/hr/device-lookup', apiHeaders($raw))
            ->assertStatus(422)
            ->assertJsonFragment(['ok' => false])
            ->assertJsonFragment(['error' => 'Missing required query parameter: upn']);
    });

    it('returns 404 when no employee matches the UPN', function () {
        [$raw] = makeApiKey();

        $this->getJson('/api/hr/device-lookup?upn=nobody@nowhere.com', apiHeaders($raw))
            ->assertStatus(404)
            ->assertJsonFragment(['ok' => false])
            ->assertJsonFragment(['upn' => 'nobody@nowhere.com']);
    });

    it('returns 200 with empty devices when employee has no assignments', function () {
        [$raw] = makeApiKey();
        $branch   = Branch::create(['name' => 'HQ']);
        $employee = Employee::create([
            'name'      => 'John Doe',
            'email'     => 'john.doe@company.com',
            'branch_id' => $branch->id,
            'status'    => 'active',
        ]);

        $this->getJson('/api/hr/device-lookup?upn=john.doe@company.com', apiHeaders($raw))
            ->assertStatus(200)
            ->assertJsonFragment(['ok'       => true])
            ->assertJsonFragment(['employee' => 'John Doe'])
            ->assertJsonFragment(['devices'  => []])
            ->assertJsonPath('teamviewer_id', null);
    });

    it('resolves employee via IdentityUser UPN when direct email does not match', function () {
        [$raw] = makeApiKey();
        $branch   = Branch::create(['name' => 'HQ']);
        $employee = Employee::create([
            'name'      => 'Jane Smith',
            'email'     => 'j.smith@internal.local',
            'branch_id' => $branch->id,
            'status'    => 'active',
        ]);
        IdentityUser::create([
            'user_principal_name' => 'jane.smith@company.com',
            'mail'                => 'j.smith@internal.local',
            'display_name'        => 'Jane Smith',
        ]);

        $this->getJson('/api/hr/device-lookup?upn=jane.smith@company.com', apiHeaders($raw))
            ->assertStatus(200)
            ->assertJsonFragment(['ok'       => true])
            ->assertJsonFragment(['employee' => 'Jane Smith']);
    });

    it('returns device info including teamviewer_id for assigned device', function () {
        [$raw] = makeApiKey();
        $branch = Branch::create(['name' => 'HQ']);

        $employee = Employee::create([
            'name'      => 'Alice Admin',
            'email'     => 'alice@company.com',
            'branch_id' => $branch->id,
            'status'    => 'active',
        ]);

        $device = Device::create([
            'name'         => 'ALICE-PC',
            'type'         => 'laptop',
            'asset_code'   => 'SG-LT-000001',
            'branch_id'    => $branch->id,
            'mac_address'  => 'AA:BB:CC:DD:EE:FF',
            'ip_address'   => '192.168.1.50',
        ]);

        $az = AzureDevice::create([
            'device_id'      => $device->id,
            'display_name'   => 'ALICE-PC',
            'teamviewer_id'  => '987654321',
            'tv_version'     => '15.40.0',
            'cpu_name'       => 'Intel Core i7-1165G7',
            'ethernet_mac'   => 'AA:BB:CC:DD:EE:FF',
        ]);

        EmployeeAsset::create([
            'employee_id'   => $employee->id,
            'asset_id'      => $device->id,
            'assigned_date' => now()->subDays(30),
            'returned_date' => null,
        ]);

        $response = $this->getJson('/api/hr/device-lookup?upn=alice@company.com', apiHeaders($raw))
            ->assertStatus(200)
            ->assertJsonFragment(['ok'           => true])
            ->assertJsonFragment(['employee'     => 'Alice Admin'])
            ->assertJsonFragment(['teamviewer_id'=> '987654321'])
            ->assertJsonFragment(['tv_version'   => '15.40.0']);

        $devices = $response->json('devices');
        expect($devices)->toHaveCount(1);
        expect($devices[0]['asset_code'])->toBe('SG-LT-000001');
        expect($devices[0]['cpu'])->toBe('Intel Core i7-1165G7');
        expect($devices[0]['teamviewer_id'])->toBe('987654321');
    });

    it('does not return devices with a returned_date (only current assignments)', function () {
        [$raw] = makeApiKey();
        $branch   = Branch::create(['name' => 'HQ']);
        $employee = Employee::create([
            'name'      => 'Bob Builder',
            'email'     => 'bob@company.com',
            'branch_id' => $branch->id,
            'status'    => 'active',
        ]);
        $device = Device::create([
            'name'      => 'OLD-LAPTOP',
            'type'      => 'laptop',
            'branch_id' => $branch->id,
        ]);
        // Already returned — should NOT appear
        EmployeeAsset::create([
            'employee_id'   => $employee->id,
            'asset_id'      => $device->id,
            'assigned_date' => now()->subDays(60),
            'returned_date' => now()->subDays(10),
        ]);

        $this->getJson('/api/hr/device-lookup?upn=bob@company.com', apiHeaders($raw))
            ->assertStatus(200)
            ->assertJsonFragment(['devices' => []]);
    });

    it('exposes the primary teamviewer_id at the root level from the first matched device', function () {
        [$raw] = makeApiKey();
        $branch = Branch::create(['name' => 'HQ']);

        $employee = Employee::create([
            'name' => 'Multi Device User', 'email' => 'multi@company.com',
            'branch_id' => $branch->id, 'status' => 'active',
        ]);

        // Device 1 — no TeamViewer
        $d1 = Device::create(['name' => 'PHONE-1', 'type' => 'ip_phone', 'branch_id' => $branch->id]);
        EmployeeAsset::create(['employee_id' => $employee->id, 'asset_id' => $d1->id, 'assigned_date' => now()]);

        // Device 2 — has TeamViewer
        $d2 = Device::create(['name' => 'LAPTOP-1', 'type' => 'laptop', 'branch_id' => $branch->id]);
        AzureDevice::create(['device_id' => $d2->id, 'display_name' => 'LAPTOP-1', 'teamviewer_id' => '111222333']);
        EmployeeAsset::create(['employee_id' => $employee->id, 'asset_id' => $d2->id, 'assigned_date' => now()]);

        $this->getJson('/api/hr/device-lookup?upn=multi@company.com', apiHeaders($raw))
            ->assertStatus(200)
            ->assertJsonPath('teamviewer_id', '111222333');
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// C. POST /api/hr/onboarding
// ─────────────────────────────────────────────────────────────────────────────

describe('POST /api/hr/onboarding', function () {

    it('returns 422 when required fields are missing', function () {
        [$raw] = makeApiKey();

        $this->postJson('/api/hr/onboarding', [], apiHeaders($raw))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name', 'branch_id']);
    });

    it('returns 422 when branch_id does not exist', function () {
        [$raw] = makeApiKey();

        $this->postJson('/api/hr/onboarding', [
            'first_name' => 'Ahmed',
            'last_name'  => 'Karimi',
            'branch_id'  => 99999,
        ], apiHeaders($raw))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);
    });

    it('creates a workflow and returns 201 with valid payload', function () {
        [$raw] = makeApiKey();
        $branch = Branch::create(['name' => 'Cairo HQ']);
        $dept   = Department::create(['name' => 'Engineering']);

        $this->postJson('/api/hr/onboarding', [
            'first_name'      => 'Ahmed',
            'last_name'       => 'Karimi',
            'job_title'       => 'Software Engineer',
            'department_id'   => $dept->id,
            'branch_id'       => $branch->id,
            'start_date'      => '2026-05-01',
            'manager_email'   => 'manager@company.com',
            'hr_reference'    => 'HR-2026-0045',
        ], apiHeaders($raw))
            ->assertStatus(201)
            ->assertJsonFragment(['ok' => true])
            ->assertJsonStructure(['ok', 'workflow_id', 'status', 'message']);
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// D. POST /api/hr/offboarding
// ─────────────────────────────────────────────────────────────────────────────

describe('POST /api/hr/offboarding', function () {

    it('returns 422 when required fields are missing', function () {
        [$raw] = makeApiKey();

        $this->postJson('/api/hr/offboarding', [], apiHeaders($raw))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_name', 'manager_email']);
    });

    it('creates an offboarding workflow and returns 201', function () {
        [$raw] = makeApiKey();
        $branch = Branch::create(['name' => 'Cairo HQ']);

        $this->postJson('/api/hr/offboarding', [
            'employee_name' => 'Sara Leaving',
            'upn'           => 'sara@company.com',
            'last_day'      => '2026-04-30',
            'reason'        => 'resignation',
            'manager_email' => 'manager@company.com',
            'branch_id'     => $branch->id,
            'hr_reference'  => 'HR-OFF-2026-001',
        ], apiHeaders($raw))
            ->assertStatus(201)
            ->assertJsonFragment(['ok' => true])
            ->assertJsonStructure(['ok', 'workflow_id', 'status', 'message']);
    });

    it('resolves employee by upn and links to workflow', function () {
        [$raw] = makeApiKey();
        $branch   = Branch::create(['name' => 'HQ']);
        $employee = Employee::create([
            'name'      => 'Sara Leaving',
            'email'     => 'sara@company.com',
            'branch_id' => $branch->id,
            'status'    => 'active',
        ]);

        $this->postJson('/api/hr/offboarding', [
            'employee_name' => $employee->name,
            'upn'           => $employee->email,
            'manager_email' => 'manager@company.com',
        ], apiHeaders($raw))
            ->assertStatus(201)
            ->assertJsonFragment(['ok' => true]);
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// E. POST /api/hr/group-assignment
// ─────────────────────────────────────────────────────────────────────────────

describe('POST /api/hr/group-assignment', function () {

    it('returns 422 when upn is missing', function () {
        [$raw] = makeApiKey();

        $this->postJson('/api/hr/group-assignment', [
            'group_ids' => ['some-guid-here'],
        ], apiHeaders($raw))
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'upn (employee email) is required.']);
    });

    it('returns 422 when neither group_ids nor group_names are provided', function () {
        [$raw] = makeApiKey();

        $this->postJson('/api/hr/group-assignment', [
            'upn' => 'user@company.com',
        ], apiHeaders($raw))
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'At least one of group_ids or group_names is required.']);
    });

    it('creates a group assignment workflow and returns 201 with group_ids', function () {
        [$raw] = makeApiKey();
        $branch   = Branch::create(['name' => 'HQ']);
        Employee::create([
            'name'      => 'Group User',
            'email'     => 'groupuser@company.com',
            'branch_id' => $branch->id,
            'status'    => 'active',
        ]);

        $this->postJson('/api/hr/group-assignment', [
            'upn'          => 'groupuser@company.com',
            'group_ids'    => ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            'hr_reference' => 'HR-GRP-2026-001',
        ], apiHeaders($raw))
            ->assertStatus(201)
            ->assertJsonFragment(['ok' => true])
            ->assertJsonStructure(['ok', 'workflow_id', 'assigned', 'errors', 'message']);
    });

});
