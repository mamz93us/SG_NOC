<?php

use App\Models\AssetHistory;
use App\Models\AzureDevice;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\Itam\OracleAsset;
use App\Models\Itam\OracleAssetImport;
use App\Models\WorkflowRequest;
use App\Services\AssetCodeService;
use App\Services\Itam\AssetRetirement;
use App\Services\Itam\Oracle\OracleAssetDevices;
use App\Services\Itam\Oracle\OracleAssetImporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Oracle's fixed-asset register into ITAM. What is defended: only laptops and
 * desktops come in; an employee number never lands on someone in Cairo; a
 * laptop that is an Intune device the NOC already has gains the Oracle number
 * on that asset instead of becoming a second one; a laptop nothing matches
 * becomes an asset of its holder's, flagged as not in Intune; a holder who is
 * not in the NOC gets nothing invented; a second import changes nothing it
 * already decided; and retiring, linking and undoing a match leave the asset
 * and its holder consistent.
 *
 * Tables are built directly: the full migration set does not run on SQLite.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array', 'oracle_assets.branches' => ['JED', 'RYD']]);

    foreach (['oracle_assets', 'oracle_asset_imports', 'activity_logs', 'accessory_assignments', 'license_assignments',
        'workflow_tasks', 'workflow_requests', 'asset_history', 'employee_assets', 'azure_devices', 'devices',
        'identity_users', 'employees', 'branches', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
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
        $t->string('azure_id')->nullable();
        $t->string('oracle_emp_no')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedBigInteger('linked_primary_employee_id')->nullable();
        $t->string('status')->default('active');
        $t->timestamps();
    });
    Schema::create('identity_users', function (Blueprint $t) {
        $t->id();
        $t->string('azure_id');
        $t->string('user_principal_name')->nullable();
        $t->string('mail')->nullable();
        $t->timestamps();
    });
    Schema::create('devices', function (Blueprint $t) {
        $t->id();
        $t->string('asset_code')->nullable()->unique();
        $t->string('oracle_asset_number', 40)->nullable();
        $t->string('type')->default('other');
        $t->string('name');
        $t->string('manufacturer')->nullable();
        $t->string('model')->nullable();
        $t->string('serial_number')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->string('storage_location')->nullable();
        $t->text('notes')->nullable();
        $t->string('source')->nullable();
        $t->string('source_id')->default('');
        $t->string('status')->default('active');
        $t->string('condition')->default('new');
        $t->date('purchase_date')->nullable();
        $t->date('warranty_expiry')->nullable();
        $t->string('depreciation_method')->default('none');
        $t->unsignedSmallInteger('depreciation_years')->nullable();
        $t->unsignedBigInteger('scrap_workflow_id')->nullable();
        $t->timestamps();
        $t->unique(['source', 'source_id']);
    });
    Schema::create('azure_devices', function (Blueprint $t) {
        $t->id();
        $t->string('azure_device_id')->unique();
        $t->string('intune_managed_device_id')->nullable();
        $t->string('display_name');
        $t->string('os')->nullable();
        $t->string('upn')->nullable();
        $t->string('serial_number')->nullable();
        $t->string('manufacturer')->nullable();
        $t->string('model')->nullable();
        $t->string('cpu_name')->nullable();
        $t->timestamp('enrolled_date')->nullable();
        $t->timestamp('last_sync_at')->nullable();
        $t->timestamp('last_activity_at')->nullable();
        $t->unsignedBigInteger('device_id')->nullable();
        $t->string('link_status')->default('unlinked');
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
    Schema::create('license_assignments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('license_id');
        $t->string('assignable_type');
        $t->unsignedBigInteger('assignable_id');
        $t->timestamps();
    });
    Schema::create('accessory_assignments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('accessory_id');
        $t->unsignedBigInteger('device_id')->nullable();
        $t->date('returned_date')->nullable();
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

    (require database_path('migrations/2026_09_17_100001_create_oracle_asset_register_tables.php'))->up();

    DB::table('branches')->insert([['id' => 10, 'name' => 'JED'], ['id' => 20, 'name' => 'RYD'], ['id' => 60, 'name' => 'CAI']]);

    // Asset codes without the settings table.
    app()->instance(AssetCodeService::class, new class extends AssetCodeService
    {
        private int $next = 100;

        public function __construct() {}

        public function generate(string $deviceType): string
        {
            return 'SG-'.strtoupper(substr($deviceType, 0, 3)).'-'.str_pad((string) ++$this->next, 6, '0', STR_PAD_LEFT);
        }
    });
});

function registerRow(string $asset, string $description, string $purchased, string $empNo, string $empName): array
{
    static $row = 1;

    return [
        'row' => ++$row,
        'asset_number' => ctype_digit($asset) ? (int) $asset : $asset,
        'description' => $description,
        'purchase_date' => $purchased,
        'end_date' => CarbonImmutable::parse($purchased)->addYears(3)->toDateString(),
        'emp_name' => $empName,
        'emp_no' => ctype_digit($empNo) ? (int) $empNo : $empNo,
    ];
}

function registerStaff(string $name, ?string $oracleNo, ?int $branchId, ?string $email = null): Employee
{
    return Employee::withoutEvents(fn () => Employee::create(['name' => $name, 'oracle_emp_no' => $oracleNo, 'branch_id' => $branchId, 'email' => $email, 'status' => 'active']));
}

/** An Intune laptop the Azure import already made an asset of, assigned to its user. */
function intuneAsset(Employee $holder, string $code, string $manufacturer, string $model, string $enrolled, ?string $serial = null): Device
{
    $device = Device::create([
        'type' => 'laptop', 'name' => 'PC-'.$code, 'manufacturer' => $manufacturer, 'model' => $model,
        'serial_number' => $serial ?? 'SN'.$code, 'asset_code' => $code, 'status' => 'assigned', 'condition' => 'used',
        'source' => 'azure', 'source_id' => 'az-'.$code, 'purchase_date' => $enrolled,
        'depreciation_method' => 'straight_line', 'depreciation_years' => 3,
    ]);
    AzureDevice::create([
        'azure_device_id' => 'az-'.$code, 'display_name' => 'PC-'.$code, 'os' => 'Windows', 'upn' => $holder->email,
        'serial_number' => $serial ?? 'SN'.$code, 'manufacturer' => $manufacturer, 'model' => $model,
        'enrolled_date' => $enrolled, 'device_id' => $device->id, 'link_status' => 'linked',
    ]);
    EmployeeAsset::create(['employee_id' => $holder->id, 'asset_id' => $device->id, 'assigned_date' => $enrolled, 'condition' => 'used']);

    return $device;
}

function importRegister(array $rows, bool $dryRun = false): OracleAssetImport
{
    return app(OracleAssetImporter::class)->import($rows, 'assets 7-2026.xlsx', null, $dryRun);
}

function unitOf(string $asset, string $empNo, int $unit = 1): OracleAsset
{
    return OracleAsset::where('asset_number', $asset)->where('emp_no', $empNo)->where('unit', $unit)->sole();
}

it('imports the laptops and desktops, links holders, and puts the Oracle number on the Intune asset the NOC already has', function () {
    $ahmed = registerStaff('Ahmed Manea', '2367', 10, 'ahmed@samirgroup.com');
    $saudi = registerStaff('Saeed Patel', '503', 20, 'saeed@samirgroup.com');
    registerStaff('Esraa Youssef', '503', 60, 'esraa@sssegypt.com');
    $haidar = registerStaff('Abdullah Haidar', null, 20, 'abdullah.haidar@samirgroup.com');
    $laptop = intuneAsset($ahmed, 'SG-LAP-000001', 'HP', 'HP 250 15.6 inch G10 Notebook PC', '2025-08-12');

    AzureDevice::create([
        'azure_device_id' => 'az-new', 'display_name' => 'SG-RYD-LT-77', 'os' => 'Windows', 'upn' => 'saeed@samirgroup.com',
        'serial_number' => '5CG4210XYZ', 'manufacturer' => 'HP', 'model' => 'HP Laptop 15-fd0xxx', 'enrolled_date' => '2024-02-11',
        'link_status' => 'unlinked',
    ]);

    $import = importRegister([
        registerRow('1001946', '(969K9ET)HP 250G10 i7-1355U 15 16GB/512 PC Intel i7-1355U / 15.6" FHD AG LED SVA', '2025-07-31', '2367', 'Ahmed Manea'),
        registerRow('12050', 'DELL INS 5110', '2012-05-31', '2367', 'Ahmed Manea'),
        registerRow('1001014', 'HP Laptop 15 fd0027nx- (8N2B8EA) - Core i7 - 1355U - 16GB- 512GB SSD', '2024-01-31', '503', 'Saeed Patel'),
        registerRow('1001973', 'LENOVO ThinkPad E14 Gn2 (20TA00C0AD) - i7 - 16 GB - 512GB SSD', '2022-08-31', '1973', 'Abdullah Mahmoud Mari Haidar'),
        registerRow('13175', 'HP PAV 15-N200NX-I3-317U-1.80GHZ - 4GB-500GB-WIFI - BT', '2015-09-30', '999', 'Somebody Who Left'),
        registerRow('1001445', 'OEM MS WINDOWS 11 PRO - 64 bit - ENGLISH', '2024-01-31', '2367', 'Ahmed Manea'),
        registerRow('12085', 'NEC EA261WM 26" LCD BLK CN', '2012-07-31', '2367', 'Ahmed Manea'),
    ]);

    expect($import->stat('computers'))->toBe(5)
        ->and($import->stat('skipped_other'))->toBe(2)
        ->and(OracleAsset::count())->toBe(5)
        ->and($import->stat('linked_intune'))->toBe(2)
        ->and($import->stat('created_devices'))->toBe(2)
        ->and($import->stat('no_employee'))->toBe(1);

    // The Intune asset gains Oracle's number and purchase date, and stays the same asset.
    $hp = unitOf('1001946', '2367');
    $laptop->refresh();
    expect($hp->device_id)->toBe($laptop->id)
        ->and($hp->device_match)->toBe(OracleAsset::DEVICE_MODEL)
        ->and($laptop->asset_code)->toBe('SG-LAP-000001')
        ->and($laptop->oracle_asset_number)->toBe('1001946')
        ->and($laptop->purchase_date->toDateString())->toBe('2025-07-31')
        ->and(EmployeeAsset::where('asset_id', $laptop->id)->whereNull('returned_date')->count())->toBe(1);

    // An old laptop nothing matched: a new asset of Ahmed's, not in Intune.
    $old = unitOf('12050', '2367')->device;
    expect($old->source)->toBe('oracle')
        ->and($old->oracle_asset_number)->toBe('12050')
        ->and($old->status)->toBe('assigned')
        ->and(AzureDevice::where('device_id', $old->id)->exists())->toBeFalse()
        ->and(EmployeeAsset::where('asset_id', $old->id)->whereNull('returned_date')->value('employee_id'))->toBe($ahmed->id);

    // Number 503 is Saeed in Riyadh, not Esraa in Cairo; his Intune laptop had no asset yet.
    $saeeds = unitOf('1001014', '503');
    $intune = AzureDevice::where('azure_device_id', 'az-new')->sole();
    expect($saeeds->employee_id)->toBe($saudi->id)
        ->and($saeeds->employee_match)->toBe(OracleAsset::EMPLOYEE_NUMBER_BRANCH)
        ->and($intune->device_id)->toBe($saeeds->device_id)
        ->and($intune->link_status)->toBe('linked')
        ->and($saeeds->device->source)->toBe('azure')
        ->and($saeeds->device->serial_number)->toBe('5CG4210XYZ');

    // No Oracle number in the NOC, one employee with that name.
    expect(unitOf('1001973', '1973')->employee_id)->toBe($haidar->id)
        ->and(unitOf('1001973', '1973')->employee_match)->toBe(OracleAsset::EMPLOYEE_NAME);

    // A holder the NOC does not have gets nothing invented.
    $gone = unitOf('13175', '999');
    expect($gone->employee_id)->toBeNull()
        ->and($gone->device_id)->toBeNull()
        ->and($gone->device_match)->toBeNull()
        ->and(Device::count())->toBe(4);
});

it('changes nothing it already decided on the next import, and marks what Oracle stopped listing', function () {
    $ahmed = registerStaff('Ahmed Manea', '2367', 10, 'ahmed@samirgroup.com');
    intuneAsset($ahmed, 'SG-LAP-000001', 'HP', 'HP 250 15.6 inch G10 Notebook PC', '2025-08-12');

    $rows = [
        registerRow('1001946', '(969K9ET)HP 250G10 i7-1355U 15 16GB/512 PC', '2025-07-31', '2367', 'Ahmed Manea'),
        registerRow('12050', 'DELL INS 5110', '2012-05-31', '2367', 'Ahmed Manea'),
    ];

    importRegister($rows);
    $devices = Device::count();

    $again = importRegister($rows);
    expect(Device::count())->toBe($devices)
        ->and($again->stat('unchanged'))->toBe(2)
        ->and($again->stat('created'))->toBe(0);

    $shorter = importRegister([$rows[0]]);
    expect($shorter->stat('removed'))->toBe(1)
        ->and(unitOf('12050', '2367')->removed_at)->not->toBeNull()
        ->and(unitOf('12050', '2367')->device_id)->not->toBeNull();

    expect(importRegister($rows)->stat('restored'))->toBe(1)
        ->and(unitOf('12050', '2367')->removed_at)->toBeNull()
        ->and(Device::count())->toBe($devices);
});

it('saves nothing on a dry run and still reports what it would do', function () {
    registerStaff('Ahmed Manea', '2367', 10, 'ahmed@samirgroup.com');

    $preview = importRegister([registerRow('12050', 'DELL INS 5110', '2012-05-31', '2367', 'Ahmed Manea')], dryRun: true);

    expect($preview->stat('created_devices'))->toBe(1)
        ->and($preview->exists)->toBeFalse()
        ->and(OracleAsset::count())->toBe(0)
        ->and(OracleAssetImport::count())->toBe(0)
        ->and(Device::count())->toBe(0);
});

it('gives one person holding two units of one asset two assets', function () {
    registerStaff('Hussein Al Dhamen', '1310', 20, 'hussein@samirgroup.com');

    importRegister([
        registerRow('1001230', 'DELL Vostro 3510 - i5 - 8 GB - 512 GB SSD', '2022-01-31', '1310', 'Hussein Al Dhamen'),
        registerRow('1001230', 'DELL Vostro 3510 - i5 - 8 GB - 512 GB SSD', '2022-01-31', '1310', 'Hussein Al Dhamen'),
    ]);

    expect(OracleAsset::where('asset_number', '1001230')->pluck('unit')->sort()->values()->all())->toBe([1, 2])
        ->and(OracleAsset::whereNotNull('device_id')->distinct()->count('device_id'))->toBe(2);
});

it('retires an asset: the holder\'s assignment closes and the reason is kept', function () {
    $ahmed = registerStaff('Ahmed Manea', '2367', 10, 'ahmed@samirgroup.com');
    importRegister([registerRow('12050', 'DELL INS 5110', '2012-05-31', '2367', 'Ahmed Manea')]);
    $device = unitOf('12050', '2367')->device;

    app(AssetRetirement::class)->retire($device, 'Gone since 2016.', CarbonImmutable::parse('2026-09-17'));

    $device->refresh();
    expect($device->status)->toBe('retired')
        ->and(EmployeeAsset::where('asset_id', $device->id)->whereNull('returned_date')->exists())->toBeFalse()
        ->and(AssetHistory::where('device_id', $device->id)->where('event_type', 'retired')->value('description'))->toBe('Retired: Gone since 2016.');

    expect(fn () => app(AssetRetirement::class)->retire($device, 'Again.', CarbonImmutable::now()))->toThrow(DomainException::class);
});

it('will not retire an asset waiting in a scrap request', function () {
    registerStaff('Ahmed Manea', '2367', 10, 'ahmed@samirgroup.com');
    importRegister([registerRow('12050', 'DELL INS 5110', '2012-05-31', '2367', 'Ahmed Manea')]);
    $device = unitOf('12050', '2367')->device;

    WorkflowRequest::create(['type' => 'asset_scrap', 'title' => 'Scrap', 'status' => 'pending', 'payload' => ['device_ids' => [(string) $device->id]], 'current_step' => 1, 'total_steps' => 2]);

    expect(fn () => app(AssetRetirement::class)->retire($device, 'Broken.', CarbonImmutable::now()))
        ->toThrow(DomainException::class, 'scrap request');
});

it('moves the Oracle unit onto an Intune device that has an asset, and deletes the asset the import made', function () {
    $bader = registerStaff('Bader Alharbi', '162', 20, 'bader@samirgroup.com');
    $first = intuneAsset($bader, 'SG-LAP-000010', 'HP', 'HP Laptop 15-fd0xxx', '2023-03-01');
    intuneAsset($bader, 'SG-LAP-000011', 'HP', 'HP Laptop 15-dw4xxx', '2023-03-02');

    // Two HP laptops in Intune and no model in the description: nothing is guessed.
    importRegister([registerRow('1001593', 'HP Laptop | Maldives 22C1 | Core i7-1255U - U15 | 16GB DDR4', '2023-02-28', '162', 'Bader Alharbi')]);
    $unit = unitOf('1001593', '162');
    $created = $unit->device;
    expect($created->source)->toBe('oracle')
        ->and($unit->match_evidence['suggestions'])->toHaveCount(2);

    $result = app(OracleAssetDevices::class)->linkIntune($created, AzureDevice::where('device_id', $first->id)->sole(), null);

    $unit->refresh();
    expect($result->id)->toBe($first->id)
        ->and($unit->device_id)->toBe($first->id)
        ->and($unit->device_match)->toBe(OracleAsset::DEVICE_MANUAL)
        ->and($first->fresh()->oracle_asset_number)->toBe('1001593')
        ->and(Device::find($created->id))->toBeNull()
        ->and(EmployeeAsset::where('asset_id', $first->id)->whereNull('returned_date')->count())->toBe(1);
});

it('will not move an Oracle unit onto somebody else\'s laptop', function () {
    $bader = registerStaff('Bader Alharbi', '162', 20, 'bader@samirgroup.com');
    $other = registerStaff('Ibrahim Syed', '285', 10, 'ibrahim@samirgroup.com');
    $theirs = intuneAsset($other, 'SG-LAP-000020', 'Dell Inc.', 'Vostro 15 3510', '2022-02-01');

    importRegister([registerRow('1000925', 'DELL Vostro 3510 - i5 - 8 GB', '2022-01-31', '162', 'Bader Alharbi')]);
    $created = unitOf('1000925', '162')->device;

    expect(fn () => app(OracleAssetDevices::class)->linkIntune($created, AzureDevice::where('device_id', $theirs->id)->sole(), null))
        ->toThrow(DomainException::class, 'Ibrahim Syed');
});

it('undoes a wrong match: the asset gets its own dates back and the unit an asset of its own', function () {
    $ahmed = registerStaff('Ahmed Manea', '2367', 10, 'ahmed@samirgroup.com');
    $laptop = intuneAsset($ahmed, 'SG-LAP-000001', 'HP', 'HP 250 15.6 inch G10 Notebook PC', '2025-08-12');

    importRegister([registerRow('1001946', '(969K9ET)HP 250G10 i7-1355U 15 16GB/512 PC', '2025-07-31', '2367', 'Ahmed Manea')]);
    $unit = unitOf('1001946', '2367');
    expect($unit->device_id)->toBe($laptop->id);

    $own = app(OracleAssetDevices::class)->unmatch($unit, null);

    $laptop->refresh();
    $unit->refresh();
    expect($laptop->oracle_asset_number)->toBeNull()
        ->and($laptop->purchase_date->toDateString())->toBe('2025-08-12')
        ->and($unit->device_id)->toBe($own->id)
        ->and($own->source)->toBe('oracle')
        ->and(EmployeeAsset::where('asset_id', $laptop->id)->whereNull('returned_date')->value('employee_id'))->toBe($ahmed->id);
});
