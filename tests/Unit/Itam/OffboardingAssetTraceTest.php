<?php

use App\Jobs\Offboarding\ApplyAssetDecisionsJob;
use App\Models\AssetHistory;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\OffboardingWorkflow;
use App\Models\WorkflowRequest;
use App\Services\Itam\AssetMovements;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a leaver's assets leave behind.
 *
 * Offboarding closed the assignment and said so in its own workflow log, but
 * wrote nothing to the asset's history — so a hand-back appeared nowhere on the
 * device's History tab and nowhere on the Asset Movements report finance works
 * from, while the same move made by hand from the employee page appeared in
 * both. A leaver returning twelve laptops left no trace a reader of either
 * would find.
 *
 * Tables are built directly: the full migration set does not run on SQLite.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['activity_logs', 'workflow_logs', 'workflow_tasks', 'asset_history', 'employee_assets',
        'offboarding_workflows', 'workflow_requests', 'devices', 'employees', 'branches', 'users'] as $table) {
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
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('oracle_emp_no')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedBigInteger('linked_primary_employee_id')->nullable();
        $t->string('status')->default('active');
        $t->timestamps();
    });
    Schema::create('devices', function (Blueprint $t) {
        $t->id();
        $t->string('asset_code')->nullable()->unique();
        $t->string('oracle_asset_number', 40)->nullable();
        $t->string('type')->default('laptop');
        $t->string('name');
        $t->string('serial_number')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->string('status')->default('assigned');
        $t->decimal('purchase_cost', 12, 2)->nullable();
        $t->string('currency', 8)->nullable();
        $t->date('purchase_date')->nullable();
        $t->timestamps();
    });
    Schema::create('employee_assets', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('employee_id');
        $t->unsignedBigInteger('asset_id');
        $t->date('assigned_date')->nullable();
        $t->date('returned_date')->nullable();
        $t->string('condition')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });
    Schema::create('asset_history', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('device_id');
        $t->enum('event_type', AssetHistory::EVENT_TYPES);
        $t->unsignedBigInteger('user_id')->nullable();
        $t->text('description')->nullable();
        $t->json('meta')->nullable();
        $t->timestamp('created_at')->nullable();
    });
    Schema::create('workflow_requests', function (Blueprint $t) {
        $t->id();
        $t->string('type');
        $t->string('title')->nullable();
        $t->json('payload')->nullable();
        $t->string('status')->default('pending');
        $t->unsignedInteger('current_step')->default(1);
        $t->unsignedInteger('total_steps')->default(1);
        $t->timestamps();
    });
    Schema::create('workflow_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('workflow_id');
        $t->string('level')->nullable();
        $t->text('message')->nullable();
        $t->json('context')->nullable();
        $t->timestamp('created_at')->nullable();
    });
    Schema::create('workflow_tasks', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('workflow_id')->nullable();
        $t->string('type')->nullable();
        $t->string('status')->default('pending');
        $t->unsignedBigInteger('employee_id')->nullable();
        $t->unsignedBigInteger('asset_id')->nullable();
        $t->timestamps();
    });
    Schema::create('offboarding_workflows', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('workflow_id');
        $t->unsignedBigInteger('employee_id');
        $t->string('status')->default('pending');
        $t->string('asset_action')->nullable();
        $t->unsignedBigInteger('asset_target_employee_id')->nullable();
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
        $t->timestamps();
    });
});

function leaverWithLaptop(string $action, ?Employee $target = null): array
{
    $leaver = Employee::withoutEvents(fn () => Employee::create([
        'name' => 'Ahmed Manea', 'branch_id' => 10, 'status' => 'active', 'oracle_emp_no' => '2367',
    ]));

    $device = Device::create([
        'asset_code' => 'SG-LAP-000300', 'oracle_asset_number' => '1001946', 'type' => 'laptop',
        'name' => 'PC-300', 'serial_number' => 'SN-300', 'status' => 'assigned', 'branch_id' => 10,
        'purchase_cost' => 4200.00, 'currency' => 'SAR', 'purchase_date' => '2025-07-31',
    ]);
    EmployeeAsset::create([
        'employee_id' => $leaver->id, 'asset_id' => $device->id, 'assigned_date' => '2025-08-01',
    ]);

    $workflow = WorkflowRequest::create(['type' => 'employee_offboarding', 'title' => 'Offboarding']);
    $ow = OffboardingWorkflow::create([
        'workflow_id' => $workflow->id,
        'employee_id' => $leaver->id,
        'asset_action' => $action,
        'asset_target_employee_id' => $target?->id,
    ]);

    return [$ow, $leaver, $device];
}

it('records an offboarding hand-back on the asset, and lists it for finance', function () {
    [$ow, $leaver, $device] = leaverWithLaptop('return_to_it');

    (new ApplyAssetDecisionsJob($ow->id))->handle(app(App\Services\Workflow\WorkflowEngine::class));

    $event = AssetHistory::where('device_id', $device->id)->sole();

    expect(EmployeeAsset::where('asset_id', $device->id)->whereNull('returned_date')->exists())->toBeFalse()
        ->and($event->event_type)->toBe('returned')
        ->and($event->description)->toContain('Returned to IT inventory')
        ->and($event->meta['from_employee'])->toBe('Ahmed Manea')
        ->and($event->meta['from_employee_no'])->toBe('2367')
        ->and($event->meta['offboarding_id'])->toBe($ow->id);

    $rows = app(AssetMovements::class)->rows([
        'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(),
    ]);
    $row = $rows->firstWhere('kind', 'return');

    expect($row)->not->toBeNull()
        ->and($row['kind_label'])->toBe('Returned to IT')
        ->and($row['asset_code'])->toBe('SG-LAP-000300')
        ->and($row['oracle_asset_number'])->toBe('1001946')
        ->and($row['from'])->toBe('Ahmed Manea')
        ->and($row['from_no'])->toBe('2367')
        ->and($row['to'])->toBeNull();
});

it('records an offboarding transfer as a transfer, with both sides and one handover group', function () {
    $target = Employee::withoutEvents(fn () => Employee::create([
        'name' => 'Bader Alharbi', 'branch_id' => 20, 'status' => 'active', 'oracle_emp_no' => '4102',
    ]));
    [$ow, $leaver, $device] = leaverWithLaptop('transfer', $target);

    (new ApplyAssetDecisionsJob($ow->id))->handle(app(App\Services\Workflow\WorkflowEngine::class));

    $event = AssetHistory::where('device_id', $device->id)->sole();

    expect($event->event_type)->toBe('transferred')
        ->and($event->meta['from_employee'])->toBe('Ahmed Manea')
        ->and($event->meta['to_employee'])->toBe('Bader Alharbi')
        ->and($event->meta['to_employee_no'])->toBe('4102')
        // The handover slip prints a group, so an offboarding transfer has one too.
        ->and($event->meta['transfer_group_id'])->not->toBeEmpty()
        ->and(EmployeeAsset::where('asset_id', $device->id)->whereNull('returned_date')->value('employee_id'))
        ->toBe($target->id);

    $row = app(AssetMovements::class)->rows([
        'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(),
    ])->firstWhere('kind', 'transfer');

    expect($row['from'])->toBe('Ahmed Manea')
        ->and($row['from_no'])->toBe('2367')
        ->and($row['to'])->toBe('Bader Alharbi')
        ->and($row['to_no'])->toBe('4102');
});
