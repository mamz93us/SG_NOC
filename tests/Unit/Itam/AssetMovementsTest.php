<?php

use App\Http\Controllers\Admin\AssetScrapController;
use App\Http\Controllers\Admin\AssetTransferController;
use App\Models\AssetHistory;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Services\Itam\AssetMovements;
use App\Services\Itam\AssetReasons;
use App\Services\Itam\AssetRetirement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moving assets, and the list finance gets. What is defended: a transfer only
 * happens from the employee who actually holds the asset, both sides of it are
 * recorded, a scrap and a retirement keep the reason as a code from the list
 * plus whoever held it, and the movements report reads all three back with the
 * Oracle asset number finance posts against.
 *
 * Tables are built directly: the full migration set does not run on SQLite.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['activity_logs', 'asset_history', 'employee_assets', 'accessories', 'workflow_tasks', 'workflow_steps',
        'workflow_requests', 'devices', 'employees', 'branches', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('role', 50)->default('viewer');
        $t->timestamps();
    });
    Schema::create('roles', function (Blueprint $t) {
        $t->id();
        $t->string('slug', 50)->unique();
        $t->string('name', 100);
        $t->boolean('is_super')->default(false);
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
        $t->string('manufacturer')->nullable();
        $t->string('model')->nullable();
        $t->string('serial_number')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->string('storage_location')->nullable();
        $t->text('notes')->nullable();
        $t->string('source')->nullable();
        $t->string('source_id')->default('');
        $t->string('status')->default('assigned');
        $t->string('condition')->default('used');
        $t->date('purchase_date')->nullable();
        $t->decimal('purchase_cost', 15, 2)->nullable();
        $t->string('currency', 3)->default('SAR');
        $t->string('depreciation_method')->default('none');
        $t->unsignedSmallInteger('depreciation_years')->nullable();
        $t->unsignedBigInteger('scrap_workflow_id')->nullable();
        $t->timestamps();
    });
    Schema::create('employee_assets', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('employee_id');
        $t->unsignedBigInteger('asset_id');
        $t->date('assigned_date');
        $t->date('returned_date')->nullable();
        $t->string('condition')->default('good');
        $t->text('notes')->nullable();
        $t->timestamps();
    });
    Schema::create('asset_history', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('device_id');
        $t->string('event_type');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->text('description')->nullable();
        $t->json('meta')->nullable();
        $t->timestamp('created_at')->nullable();
    });
    Schema::create('workflow_requests', function (Blueprint $t) {
        $t->id();
        $t->string('type');
        $t->string('title')->nullable();
        $t->text('description')->nullable();
        $t->json('payload')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedBigInteger('requested_by')->nullable();
        $t->string('status')->default('pending');
        $t->unsignedInteger('current_step')->default(1);
        $t->unsignedInteger('total_steps')->default(1);
        $t->timestamps();
    });
    Schema::create('workflow_steps', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('workflow_id');
        $t->unsignedInteger('step_number');
        $t->string('approver_role')->nullable();
        $t->unsignedBigInteger('approver_id')->nullable();
        $t->unsignedBigInteger('acted_by')->nullable();
        $t->timestamp('acted_at')->nullable();
        $t->text('comments')->nullable();
        $t->string('status')->default('pending');
        $t->string('step_type')->default('approval');
        $t->timestamps();
    });
    Schema::create('workflow_tasks', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('workflow_id');
        $t->string('type');
        $t->string('status')->default('pending');
        $t->json('payload')->nullable();
        $t->text('notes')->nullable();
        $t->timestamp('completed_at')->nullable();
        $t->unsignedBigInteger('completed_by')->nullable();
        $t->timestamps();
    });
    Schema::create('accessories', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('asset_code')->nullable();
        $t->string('status')->default('active');
        $t->unsignedInteger('quantity_total')->default(0);
        $t->unsignedInteger('quantity_available')->default(0);
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedBigInteger('scrap_workflow_id')->nullable();
        $t->timestamps();
    });
    Schema::create('activity_logs', function (Blueprint $t) {
        $t->id();
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->string('model_label', 150)->nullable();
        $t->string('action');
        $t->json('changes')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('actor_label', 100)->nullable();
        $t->string('ip_address')->nullable();
        $t->text('user_agent')->nullable();
        $t->timestamps();
    });

    DB::table('branches')->insert([['id' => 10, 'name' => 'JED'], ['id' => 20, 'name' => 'RYD']]);
    DB::table('roles')->insert(['slug' => 'super_admin', 'name' => 'Super Admin', 'is_super' => true]);
});

function movementStaff(string $name, int $branchId): Employee
{
    return Employee::withoutEvents(fn () => Employee::create(['name' => $name, 'branch_id' => $branchId, 'status' => 'active']));
}

function movementAsset(Employee $holder, string $code, string $oracleNumber = '1001946', ?float $cost = 4200.00): Device
{
    $device = Device::create([
        'type' => 'laptop', 'name' => 'PC-'.$code, 'manufacturer' => 'HP', 'model' => 'HP 250 G10',
        'serial_number' => 'SN'.$code, 'asset_code' => $code, 'oracle_asset_number' => $oracleNumber,
        'status' => 'assigned', 'condition' => 'used', 'source' => 'azure', 'source_id' => 'az-'.$code,
        'branch_id' => $holder->branch_id, 'purchase_date' => '2025-07-31', 'purchase_cost' => $cost, 'currency' => 'SAR',
    ]);
    EmployeeAsset::create(['employee_id' => $holder->id, 'asset_id' => $device->id, 'assigned_date' => '2025-08-01', 'condition' => 'used']);

    return $device;
}

function actAsSuperAdmin(): User
{
    $user = User::create(['name' => 'IT Manager', 'email' => 'it@samirgroup.com', 'role' => 'super_admin']);
    Auth::setUser($user);

    return $user;
}

function transferRequest(array $data): Request
{
    return Request::create('/admin/itam/transfer/device/1', 'POST', $data);
}

it('composes a reason from the list and the detail', function () {
    expect(AssetReasons::compose(AssetReasons::scrapLabel('damaged'), 'Screen and board, quote 2,300'))
        ->toBe('Damaged beyond repair — Screen and board, quote 2,300')
        ->and(AssetReasons::compose(AssetReasons::retireLabel('lost'), null))->toBe('Lost')
        ->and(AssetReasons::label('not_with_holder'))->toBe('No longer with the employee')
        ->and(AssetReasons::label('nonsense'))->toBeNull();
});

it('hands an asset from one employee to another and records both sides', function () {
    $ahmed = movementStaff('Ahmed Manea', 10);
    $bader = movementStaff('Bader Alharbi', 20);
    $device = movementAsset($ahmed, 'SG-LAP-000001');
    actAsSuperAdmin();

    $response = app(AssetTransferController::class)->transferDevice(transferRequest([
        'to_employee_id' => $bader->id,
        'transfer_date' => '2026-09-20',
        'condition' => 'good',
        'notes' => 'Ahmed moved to Riyadh.',
    ]), $device);

    $closed = EmployeeAsset::where('asset_id', $device->id)->where('employee_id', $ahmed->id)->sole();
    $opened = EmployeeAsset::where('asset_id', $device->id)->where('employee_id', $bader->id)->sole();
    $event = AssetHistory::where('device_id', $device->id)->where('event_type', 'transferred')->sole();

    expect($closed->returned_date->toDateString())->toBe('2026-09-20')
        ->and($opened->returned_date)->toBeNull()
        ->and($opened->assigned_date->toDateString())->toBe('2026-09-20')
        ->and($device->fresh()->branch_id)->toBe(20)
        ->and($event->meta['from_employee'])->toBe('Ahmed Manea')
        ->and($event->meta['to_employee'])->toBe('Bader Alharbi')
        ->and($event->meta['transfer_group_id'])->not->toBeEmpty()
        ->and($response->getTargetUrl())->toContain('/print');
});

it('refuses a transfer to the holder, of an asset nobody holds, or of one waiting to be scrapped', function () {
    $ahmed = movementStaff('Ahmed Manea', 10);
    $bader = movementStaff('Bader Alharbi', 20);
    $device = movementAsset($ahmed, 'SG-LAP-000002');
    actAsSuperAdmin();

    $controller = app(AssetTransferController::class);
    $data = ['transfer_date' => '2026-09-20', 'condition' => 'good'];

    expect(session()->get('error'))->toBeNull();
    $controller->transferDevice(transferRequest($data + ['to_employee_id' => $ahmed->id]), $device);
    expect(session()->get('error'))->toContain('already holds it');

    WorkflowRequest::create(['type' => 'asset_scrap', 'title' => 'Scrap', 'status' => 'pending',
        'payload' => ['device_ids' => [(string) $device->id]], 'current_step' => 1, 'total_steps' => 2]);
    $controller->transferDevice(transferRequest($data + ['to_employee_id' => $bader->id]), $device);
    expect(session()->get('error'))->toContain('scrap request');

    $loose = Device::create(['type' => 'laptop', 'name' => 'Spare', 'asset_code' => 'SG-LAP-000003', 'status' => 'available', 'source' => 'manual', 'source_id' => 'm-3']);
    $controller->transferDevice(transferRequest($data + ['to_employee_id' => $bader->id]), $loose);
    expect(session()->get('error'))->toContain('not assigned to anyone');

    expect(EmployeeAsset::whereNull('returned_date')->where('asset_id', $device->id)->value('employee_id'))->toBe($ahmed->id);
});

it('keeps the reason code when an asset is retired', function () {
    $ahmed = movementStaff('Ahmed Manea', 10);
    $device = movementAsset($ahmed, 'SG-LAP-000004');

    app(AssetRetirement::class)->retire($device, AssetReasons::compose(AssetReasons::retireLabel('lost'), 'Left in a taxi'), CarbonImmutable::parse('2026-09-19'), 'lost');

    $event = AssetHistory::where('device_id', $device->id)->where('event_type', 'retired')->sole();
    expect($device->fresh()->status)->toBe('retired')
        ->and($event->meta['reason_code'])->toBe('lost')
        ->and($event->meta['reason'])->toBe('Lost — Left in a taxi')
        ->and($event->meta['holder'])->toBe('Ahmed Manea');
});

it('keeps the scrap reason and who held it when the request is approved', function () {
    $ahmed = movementStaff('Ahmed Manea', 10);
    $device = movementAsset($ahmed, 'SG-LAP-000005');
    actAsSuperAdmin();

    app(AssetScrapController::class)->store(Request::create('/admin/itam/scrap', 'POST', [
        'device_ids' => [$device->id],
        'reason_code' => 'damaged',
        'reason' => 'Screen and board',
        'disposal_method' => 'recycle',
    ]));

    $workflow = WorkflowRequest::where('type', 'asset_scrap')->sole();
    expect($workflow->payload['reason_code'])->toBe('damaged')
        ->and($workflow->payload['reason'])->toBe('Damaged beyond repair — Screen and board');

    app(AssetScrapController::class)->approve(Request::create('/', 'POST'), $workflow);
    app(AssetScrapController::class)->approve(Request::create('/', 'POST'), $workflow->fresh());

    $event = AssetHistory::where('device_id', $device->id)->where('event_type', 'scrapped')->sole();
    expect($device->fresh()->status)->toBe('scrapped')
        ->and($event->meta['reason_code'])->toBe('damaged')
        ->and($event->meta['holder'])->toBe('Ahmed Manea')
        ->and($event->meta['disposal_method'])->toBe('recycle')
        ->and(EmployeeAsset::whereNull('returned_date')->where('asset_id', $device->id)->exists())->toBeFalse();
});

it('lists transfers, retirements and scraps for finance, with the Oracle number and the reason', function () {
    $ahmed = movementStaff('Ahmed Manea', 10);
    $bader = movementStaff('Bader Alharbi', 20);
    actAsSuperAdmin();

    $moved = movementAsset($ahmed, 'SG-LAP-000010', '1001946', 4200.00);
    app(AssetTransferController::class)->transferDevice(transferRequest([
        'to_employee_id' => $bader->id, 'transfer_date' => '2026-09-20', 'condition' => 'good',
    ]), $moved);

    $retired = movementAsset($ahmed, 'SG-LAP-000011', '12050', 1500.00);
    app(AssetRetirement::class)->retire($retired, AssetReasons::compose(AssetReasons::retireLabel('end_of_life'), null), CarbonImmutable::parse('2026-09-18'), 'end_of_life');

    $movements = app(AssetMovements::class);
    $rows = $movements->rows(['from' => '2026-09-01', 'to' => '2026-09-30']);

    $transfer = $rows->firstWhere('kind', 'transfer');
    $retire = $rows->firstWhere('kind', 'retire');

    expect($rows)->toHaveCount(2)
        ->and($transfer['oracle_asset_number'])->toBe('1001946')
        ->and($transfer['from'])->toBe('Ahmed Manea')
        ->and($transfer['to'])->toBe('Bader Alharbi')
        ->and($retire['from'])->toBe('Ahmed Manea')
        ->and($retire['to'])->toBeNull()
        ->and($retire['reason_label'])->toBe('End of life — too old to use')
        ->and($retire['purchase_cost'])->toBe(1500.00);

    $summary = $movements->summary($rows);
    expect($summary['count'])->toBe(2)
        ->and($summary['kinds']['transfer'])->toBe(1)
        // Only what left the books is counted, so the transferred laptop's cost is not.
        ->and($summary['cost']['SAR'])->toBe(1500.00);

    expect($movements->rows(['from' => '2026-09-01', 'to' => '2026-09-30', 'kind' => 'retire']))->toHaveCount(1)
        ->and($movements->rows(['from' => '2026-09-01', 'to' => '2026-09-19']))->toHaveCount(1)
        ->and($movements->rows(['from' => '2026-09-01', 'to' => '2026-09-30', 'oracle' => '12050']))->toHaveCount(1);
});
