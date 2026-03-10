<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SensorMetric;
use App\Models\MonitoredHost;

$hosts = MonitoredHost::where('snmp_enabled', true)->get();

foreach ($hosts as $h) {
    $latestMetric = SensorMetric::whereIn('sensor_id', $h->snmpSensors()->pluck('id'))
        ->latest('recorded_at')
        ->first();
    echo "Host: " . $h->name . " | Latest Metric: " . ($latestMetric ? $latestMetric->recorded_at : 'Never') . "\n";
}
