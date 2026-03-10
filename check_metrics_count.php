<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SensorMetric;
use App\Models\SnmpSensor;
use App\Models\MonitoredHost;

$h = MonitoredHost::where('ip', '10.1.0.1')->first();
if (!$h) die("HOST NOT FOUND\n");

echo "Host: " . $h->name . " (" . $h->ip . ")\n";
echo "Sensors: " . $h->snmpSensors()->count() . "\n";

$latestMetric = SensorMetric::whereIn('sensor_id', $h->snmpSensors()->pluck('id'))
    ->latest('recorded_at')
    ->first();

echo "Latest Metric for this host: " . ($latestMetric ? $latestMetric->recorded_at : 'Never') . "\n";

$metricsInLast10Min = SensorMetric::whereIn('sensor_id', $h->snmpSensors()->pluck('id'))
    ->where('recorded_at', '>', now()->subMinutes(10))
    ->count();

echo "Metrics created in last 10 minutes: " . $metricsInLast10Min . "\n";
