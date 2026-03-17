<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Device;
use App\Models\DeviceModel;

$dm = DeviceModel::where('name', 'like', '%2717%')->first();
if (!$dm) {
    echo "DeviceModel Dell 2717 not found.\n";
    $all = DeviceModel::limit(5)->get();
    echo "Existing models: " . $all->pluck('name')->implode(', ') . "\n";
    exit;
}

echo "Found DeviceModel: " . $dm->id . " - " . $dm->manufacturer . " " . $dm->name . "\n";

$data = [
    'type' => 'monitor',
    'name' => 'Test Batch Sync',
    'device_model_id' => $dm->id,
    'serial_number' => 'SYNC-TEST-' . time(),
    'status' => 'available',
    'condition' => 'new',
    'source' => 'manual',
];

// Manual sync logic as in controller
if (!empty($data['device_model_id'])) {
    $dm_found = DeviceModel::find($data['device_model_id']);
    if ($dm_found) {
        $data['manufacturer'] = $dm_found->manufacturer;
        $data['model']        = $dm_found->name;
    }
}

echo "Data to create: " . json_encode($data) . "\n";

$device = Device::create($data);

if ($device) {
    echo "Created Device ID: " . $device->id . "\n";
    echo "Device Manufacturer: [" . $device->manufacturer . "]\n";
    echo "Device Model: [" . $device->model . "]\n";
} else {
    echo "Failed to create device.\n";
}
