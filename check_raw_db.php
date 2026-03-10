<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$h = DB::table('monitored_hosts')->where('ip', '10.1.0.1')->first();
echo "RAW_COMMUNITY: [" . ($h->snmp_community ?? 'NULL') . "]\n";
echo "LEN: " . strlen($h->snmp_community ?? '') . "\n";
