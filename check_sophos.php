<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$h = \App\Models\MonitoredHost::where('ip', '10.1.0.1')->first();
echo "IP: " . $h->ip . "\n";
echo "Community: " . $h->snmp_community . "\n";
echo "Version: " . $h->snmp_version . "\n";
echo "Sensors: " . $h->snmpSensors()->count() . "\n";
