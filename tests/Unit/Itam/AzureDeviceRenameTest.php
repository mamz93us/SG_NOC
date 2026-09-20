<?php

use App\Models\AssetHistory;
use App\Models\AzureDevice;
use App\Models\Device;
use App\Services\AzureDeviceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An Intune rename reaching the ITAM asset.
 *
 * `asset_history.event_type` is an ENUM of sixteen lifecycle events and
 * production MySQL runs strict, so recording the rename as 'updated' threw on
 * every rename. The throw was caught per device in syncDevices(), which counted
 * the device as skipped — before auto-link and auto-assign ran — and logged a
 * warning that LOG_LEVEL=error never wrote to disk. NOC2 had twelve linked
 * devices whose Intune name had never reached devices.name, and not one line
 * about it anywhere.
 *
 * `event_type` is built here as a real enum, so SQLite refuses an unknown value
 * the way MySQL does and this test fails against the old code rather than
 * passing where the bug lives.
 *
 * Tables are built directly: the full migration set does not run on SQLite.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['activity_logs', 'asset_history', 'azure_devices', 'devices', 'settings', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });
    // AzureDeviceService reads Settings when it is constructed.
    Schema::create('settings', function (Blueprint $t) {
        $t->id();
        $t->string('company_name')->nullable();
        $t->string('company_logo')->nullable();
        $t->boolean('sso_enabled')->default(false);
        $t->string('sso_default_role')->nullable();
        $t->string('graph_tenant_id')->nullable();
        $t->string('graph_client_id')->nullable();
        $t->text('graph_client_secret')->nullable();
        $t->timestamps();
    });
    Schema::create('devices', function (Blueprint $t) {
        $t->id();
        $t->string('asset_code')->nullable()->unique();
        $t->string('type')->default('laptop');
        $t->string('name');
        $t->string('serial_number')->nullable();
        $t->string('status')->default('assigned');
        $t->string('condition')->nullable();
        $t->string('source')->nullable();
        $t->string('source_id')->nullable();
        $t->unsignedBigInteger('purchase_order_id')->nullable();
        $t->timestamps();
    });
    Schema::create('azure_devices', function (Blueprint $t) {
        $t->id();
        $t->string('azure_device_id')->unique();
        $t->string('display_name')->nullable();
        $t->string('serial_number')->nullable();
        $t->string('intune_managed_device_id')->nullable();
        $t->timestamp('intune_removed_at')->nullable();
        $t->timestamp('removed_at')->nullable();
        $t->unsignedBigInteger('device_id')->nullable();
        $t->string('link_status')->default('unlinked');
        $t->timestamps();
    });
    Schema::create('asset_history', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('device_id');
        // The production column, as the 2026_05_05 migration leaves it.
        $t->enum('event_type', AssetHistory::EVENT_TYPES);
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

function renamedPair(string $assetName, string $intuneName, string $linkStatus = 'linked'): array
{
    $device = Device::create([
        'asset_code' => 'SG-LAP-000100', 'type' => 'laptop', 'name' => $assetName,
        'serial_number' => 'SN-100', 'status' => 'assigned', 'source' => 'azure', 'source_id' => 'az-100',
    ]);

    $azure = AzureDevice::create([
        'azure_device_id' => 'az-100', 'display_name' => $intuneName, 'serial_number' => 'SN-100',
        'device_id' => $device->id, 'link_status' => $linkStatus,
    ]);

    return [$device, $azure];
}

it('writes the Intune rename to the asset and records it as an event the enum allows', function () {
    [$device, $azure] = renamedPair('SG-OLD-NAME', 'SG-NEW-NAME');

    app(AzureDeviceService::class)->syncLinkedDeviceName($azure);

    $event = AssetHistory::where('device_id', $device->id)->sole();

    expect($device->fresh()->name)->toBe('SG-NEW-NAME')
        // The whole bug: 'updated' is not one of these, so the insert threw and
        // the rename was lost along with the rest of that device's sync.
        ->and($event->event_type)->toBeIn(AssetHistory::EVENT_TYPES)
        ->and($event->event_type)->toBe('note_added')
        ->and($event->description)->toContain("from 'SG-OLD-NAME' to 'SG-NEW-NAME'")
        ->and($event->meta['old_name'])->toBe('SG-OLD-NAME')
        ->and($event->meta['new_name'])->toBe('SG-NEW-NAME');
});

it('does nothing when the name already matches, or the device is not linked', function () {
    [$same] = renamedPair('SG-SAME', 'SG-SAME');
    app(AzureDeviceService::class)->syncLinkedDeviceName(AzureDevice::sole());

    expect($same->fresh()->name)->toBe('SG-SAME')
        ->and(AssetHistory::count())->toBe(0);

    AzureDevice::query()->delete();
    Device::query()->delete();

    [$pending, $azure] = renamedPair('SG-OLD', 'SG-NEW', 'pending');
    app(AzureDeviceService::class)->syncLinkedDeviceName($azure);

    // A link waiting for approval must not rename anything yet.
    expect($pending->fresh()->name)->toBe('SG-OLD')
        ->and(AssetHistory::count())->toBe(0);
});

it('refuses an event type the history column cannot hold', function () {
    [$device] = renamedPair('SG-A', 'SG-B');

    expect(fn () => AssetHistory::record($device, 'updated', 'Renamed'))
        ->toThrow(InvalidArgumentException::class, 'updated')
        ->and(AssetHistory::count())->toBe(0);

    // And the model's list is the column's list — they are written in two places.
    $migration = file_get_contents(
        dirname(__DIR__, 3).'/database/migrations/2026_05_05_000002_extend_asset_history_event_types.php'
    );
    preg_match("/MODIFY COLUMN event_type ENUM\((.*?)\) NOT NULL/s", $migration, $enum);
    preg_match_all("/'([a-z_]+)'/", $enum[1], $values);

    expect($values[1])->toBe(AssetHistory::EVENT_TYPES);
});

it('renames from neither when two Intune devices claim one asset', function () {
    [$device, $first] = renamedPair('SG-OLD-NAME', 'SG-FIRST-NAME');

    // A second Intune device linked to the same asset, with its own name: each
    // renamed the asset to its own on every run, and its history filled up.
    $second = AzureDevice::create([
        'azure_device_id' => 'az-101', 'display_name' => 'SG-SECOND-NAME', 'serial_number' => 'SN-101',
        'device_id' => $device->id, 'link_status' => 'linked',
    ]);

    $service = app(AzureDeviceService::class);
    $service->syncLinkedDeviceName($first);
    $service->syncLinkedDeviceName($second);

    expect($device->fresh()->name)->toBe('SG-OLD-NAME')
        ->and(AssetHistory::count())->toBe(0);

    // With the stale link gone, the remaining one renames it as usual.
    $second->update(['link_status' => 'unlinked', 'device_id' => null]);
    $service->syncLinkedDeviceName($first);

    expect($device->fresh()->name)->toBe('SG-FIRST-NAME')
        ->and(AssetHistory::count())->toBe(1);
});
