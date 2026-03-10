<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\MonitoredHost;

$h = MonitoredHost::where('ip', '10.1.0.1')->first();
$sensors = $h->snmpSensors()->where('name', 'like', '%Traffic%')->get();

echo "Traffic Sensor Check for Sophos:\n";
foreach ($sensors as $s) {
    $latest = $s->sensorMetrics()->latest('recorded_at')->first();
    echo "Sensor: " . str_pad($s->name, 30) . " | Value: " . ($latest ? $latest->value : '0') . " | Time: " . ($latest ? $latest->recorded_at : 'Never') . "\n";
}
