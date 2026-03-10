<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\MonitoredHost;

$hosts = MonitoredHost::where('snmp_enabled', true)->get();

echo "Host Discovery Status Check:\n";
echo str_pad("IP", 15) . " | " . str_pad("Name", 20) . " | " . str_pad("Status", 10) . " | " . "Last SNMP Update\n";
echo str_repeat("-", 70) . "\n";

foreach ($hosts as $h) {
    echo str_pad($h->ip, 15) . " | " . 
         str_pad(substr($h->name, 0, 20), 20) . " | " . 
         str_pad($h->status, 10) . " | " . 
         ($h->last_snmp_at ?: 'Never') . "\n";
}
echo "\n";
