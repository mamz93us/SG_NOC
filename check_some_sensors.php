<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\MonitoredHost;

$h = MonitoredHost::where('ip', '10.1.0.1')->first();
echo "Host: " . $h->name . "\n";
foreach($h->snmpSensors()->where('name', 'not like', '%lo%')->limit(20)->get() as $s) {
    echo str_pad($s->name, 40) . " = " . ($s->sensorMetrics()->latest('recorded_at')->first()?->value ?? "N/A") . "\n";
}
