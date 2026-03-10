<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\MonitoredHost;

$h = MonitoredHost::where('ip', '10.1.0.1')->first();
if ($h) {
    $h->snmp_community = 'NOC';
    $h->save();
    echo "SUCCESS: Community for 10.1.0.1 set to NOC\n";
    echo "VERIFY: " . $h->snmp_community . "\n";
} else {
    echo "ERROR: Host 10.1.0.1 not found\n";
}
