<?php

use App\Models\AzureDevice;
use App\Models\Device;
use App\Services\AzureDeviceService;
use App\Services\Identity\GraphService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * What the NOC does when Microsoft stops holding a device.
 *
 * Nothing is deleted: the azure_devices row is the only record of what an asset
 * was enrolled as, so it is kept and stamped with the date it went. Two ways a
 * device goes, and they are not the same:
 *
 *  - Offboarding deletes the **Intune** managed device, which leaves the Entra
 *    object behind. Only the Intune id stops coming, so that is what says the
 *    asset left Intune — before this, the row stayed `linked` for good and every
 *    "Not in Intune" list went on counting the laptop as enrolled.
 *  - A device deleted everywhere disappears from both Graph lists.
 *
 * The marking is refused outright when the fetch cannot be trusted, because
 * both device fetches ask for a single page and a short answer would otherwise
 * mark the fleet as gone.
 *
 * Tables are built directly: the full migration set does not run on SQLite.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['activity_logs', 'employee_assets', 'asset_history', 'azure_devices', 'devices', 'settings', 'employees', 'identity_users', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });
    Schema::create('settings', function (Blueprint $t) {
        $t->id();
        $t->string('company_name')->nullable();
        $t->string('company_logo')->nullable();
        $t->boolean('sso_enabled')->default(false);
        $t->string('sso_default_role')->nullable();
        $t->timestamps();
    });
    Schema::create('identity_users', function (Blueprint $t) {
        $t->id();
        $t->string('user_principal_name')->nullable();
        $t->string('mail')->nullable();
        $t->string('azure_id')->nullable();
        $t->timestamps();
    });
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('azure_id')->nullable();
        $t->string('status')->default('active');
        $t->timestamps();
    });
    Schema::create('devices', function (Blueprint $t) {
        $t->id();
        $t->string('asset_code')->nullable()->unique();
        $t->string('type')->default('laptop');
        $t->string('name');
        $t->string('serial_number')->nullable();
        $t->string('status')->default('assigned');
        $t->string('source')->nullable();
        $t->string('source_id')->nullable();
        $t->unsignedBigInteger('purchase_order_id')->nullable();
        $t->timestamps();
    });
    Schema::create('azure_devices', function (Blueprint $t) {
        $t->id();
        $t->string('azure_device_id')->unique();
        $t->string('intune_managed_device_id')->nullable();
        $t->timestamp('intune_removed_at')->nullable();
        $t->string('display_name')->nullable();
        $t->string('device_type')->nullable();
        $t->string('os')->nullable();
        $t->string('os_version')->nullable();
        $t->string('upn')->nullable();
        $t->string('serial_number')->nullable();
        $t->string('manufacturer')->nullable();
        $t->string('model')->nullable();
        $t->timestamp('enrolled_date')->nullable();
        $t->timestamp('last_sync_at')->nullable();
        $t->timestamp('removed_at')->nullable();
        $t->timestamp('last_activity_at')->nullable();
        $t->unsignedBigInteger('device_id')->nullable();
        $t->string('link_status')->default('unlinked');
        $t->json('raw_data')->nullable();
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
        $t->string('event_type');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->text('description')->nullable();
        $t->json('meta')->nullable();
        $t->timestamp('created_at')->nullable();
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

function azureSync(): AzureDeviceService
{
    Cache::put('graph_token_client-1', 'test-token', 60);

    return new AzureDeviceService(new GraphService('tenant-1', 'client-1', 'secret-1'));
}

/**
 * @param  array<int, array{id: string, name?: string, serial?: string}>  $entra
 * @param  array<int, array{azure: string, intune: string, name?: string}>  $intune
 */
function fakeGraphDevices(array $entra, array $intune): void
{
    Http::fake([
        'graph.microsoft.com/v1.0/deviceManagement/managedDevices*' => Http::response([
            'value' => array_map(fn (array $d) => [
                'id' => $d['intune'],
                'azureADDeviceId' => $d['azure'],
                'deviceName' => $d['name'] ?? 'PC-'.$d['azure'],
                'serialNumber' => $d['serial'] ?? 'SN-'.$d['azure'],
                'operatingSystem' => 'Windows',
            ], $intune),
        ]),
        'graph.microsoft.com/v1.0/devices*' => Http::response([
            'value' => array_map(fn (array $d) => [
                'id' => 'graph-'.$d['id'],
                'deviceId' => $d['id'],
                'displayName' => $d['name'] ?? 'PC-'.$d['id'],
                'operatingSystem' => 'Windows',
                'physicalIds' => [],
            ], $entra),
        ]),
    ]);
}

function knownAzureDevice(string $azureId, ?string $intuneId = 'intune-'.'1', array $attributes = []): AzureDevice
{
    return AzureDevice::create(array_merge([
        'azure_device_id' => $azureId,
        'intune_managed_device_id' => $intuneId,
        'display_name' => 'PC-'.$azureId,
        'serial_number' => 'SN-'.$azureId,
        'link_status' => 'unlinked',
        'last_sync_at' => now()->subDay(),
    ], $attributes));
}

it('stamps a device Microsoft no longer lists, and keeps the row', function () {
    $gone = knownAzureDevice('dev-gone', 'intune-gone');
    knownAzureDevice('dev-a', 'intune-a');
    knownAzureDevice('dev-b', 'intune-b');
    knownAzureDevice('dev-c', 'intune-c');

    fakeGraphDevices(
        [['id' => 'dev-a'], ['id' => 'dev-b'], ['id' => 'dev-c']],
        [['azure' => 'dev-a', 'intune' => 'intune-a'], ['azure' => 'dev-b', 'intune' => 'intune-b'], ['azure' => 'dev-c', 'intune' => 'intune-c']]
    );

    $result = azureSync()->syncDevices();

    expect($result['removed'])->toBe(1)
        ->and(AzureDevice::count())->toBe(4)
        ->and($gone->fresh()->removed_at)->not->toBeNull()
        ->and($gone->fresh()->isInIntune())->toBeFalse()
        ->and(AzureDevice::find(2)->removed_at)->toBeNull();
});

it('says an asset left Intune when only the Entra object is left', function () {
    $device = Device::create([
        'asset_code' => 'SG-LAP-000200', 'type' => 'laptop', 'name' => 'PC-dev-a',
        'serial_number' => 'SN-dev-a', 'status' => 'assigned',
    ]);
    $azure = knownAzureDevice('dev-a', 'intune-a', ['device_id' => $device->id, 'link_status' => 'linked']);

    expect($azure->isInIntune())->toBeTrue();

    // Offboarding deleted the managed device; Entra still lists the object.
    fakeGraphDevices([['id' => 'dev-a']], []);
    azureSync()->syncDevices();

    $azure->refresh();

    expect($azure->removed_at)->toBeNull()
        ->and($azure->intune_managed_device_id)->toBeNull()
        ->and($azure->intune_removed_at)->not->toBeNull()
        ->and($azure->link_status)->toBe('linked')
        ->and($azure->isInIntune())->toBeFalse()
        ->and($azure->microsoftStateLabel())->toContain('Left Intune')
        // The trace of what it was enrolled as stays on the asset.
        ->and($azure->display_name)->toBe('PC-dev-a')
        ->and(AzureDevice::inIntune()->count())->toBe(0);
});

it('lifts the stamp when the asset is enrolled in Intune again', function () {
    $device = Device::create([
        'asset_code' => 'SG-LAP-000201', 'type' => 'laptop', 'name' => 'PC-dev-a',
        'serial_number' => 'SN-dev-a', 'status' => 'assigned',
    ]);
    // The state the previous test leaves: linked, but Intune no longer holds it.
    $azure = knownAzureDevice('dev-a', null, [
        'device_id' => $device->id, 'link_status' => 'linked', 'intune_removed_at' => now()->subMonth(),
    ]);

    fakeGraphDevices([['id' => 'dev-a']], [['azure' => 'dev-a', 'intune' => 'intune-new']]);
    azureSync()->syncDevices();

    $azure->refresh();

    expect($azure->intune_removed_at)->toBeNull()
        ->and($azure->intune_managed_device_id)->toBe('intune-new')
        ->and($azure->isInIntune())->toBeTrue()
        // Enrolled again on the same row, not a second one beside it.
        ->and(AzureDevice::count())->toBe(1);
});

it('brings a device back rather than leaving it stamped', function () {
    $away = knownAzureDevice('dev-a', 'intune-a', ['removed_at' => now()->subWeek()]);

    fakeGraphDevices([['id' => 'dev-a']], [['azure' => 'dev-a', 'intune' => 'intune-a']]);
    $result = azureSync()->syncDevices();

    expect($result['restored'])->toBe(1)
        ->and($away->fresh()->removed_at)->toBeNull();
});

it('refuses to mark anything when Graph answers with less than half the fleet', function () {
    foreach (['dev-a', 'dev-b', 'dev-c', 'dev-d'] as $id) {
        knownAzureDevice($id, 'intune-'.$id);
    }

    // One device back out of four known: a truncated or throttled page, not a fleet.
    fakeGraphDevices([['id' => 'dev-a']], [['azure' => 'dev-a', 'intune' => 'intune-dev-a']]);
    $result = azureSync()->syncDevices();

    expect($result['removed'])->toBe(0)
        ->and(AzureDevice::whereNotNull('removed_at')->count())->toBe(0);
});

it('refuses to mark anything when Graph answers with nothing at all', function () {
    knownAzureDevice('dev-a', 'intune-a');

    fakeGraphDevices([], []);
    $result = azureSync()->syncDevices();

    expect($result['removed'])->toBe(0)
        ->and(AzureDevice::whereNotNull('removed_at')->count())->toBe(0);
});

it('takes Microsoft placeholder dates for no date, instead of failing the device', function () {
    // Intune sends 0001-01-01T00:00:00Z for a date it does not hold, which MySQL
    // in strict mode refuses — two devices on NOC2 were skipped every run for it.
    Http::fake([
        'graph.microsoft.com/v1.0/deviceManagement/managedDevices*' => Http::response(['value' => [[
            'id' => 'intune-a',
            'azureADDeviceId' => 'dev-a',
            'deviceName' => 'PC-dev-a',
            'serialNumber' => 'SN-dev-a',
            'enrolledDateTime' => '0001-01-01T00:00:00Z',
            'lastSyncDateTime' => '0001-01-01T00:00:00Z',
        ]]]),
        'graph.microsoft.com/v1.0/devices*' => Http::response(['value' => [[
            'id' => 'graph-dev-a',
            'deviceId' => 'dev-a',
            'displayName' => 'PC-dev-a',
            'approximateLastSignInDateTime' => '0001-01-01T00:00:00Z',
            'physicalIds' => [],
        ]]]),
    ]);

    $result = azureSync()->syncDevices();
    $device = AzureDevice::where('azure_device_id', 'dev-a')->first();

    expect($result['skipped'])->toBe(0)
        ->and($result['synced'])->toBe(1)
        ->and($device)->not->toBeNull()
        ->and($device->enrolled_date)->toBeNull()
        ->and($device->last_activity_at)->toBeNull();
});
