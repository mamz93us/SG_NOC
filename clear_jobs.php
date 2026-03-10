<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    DB::table('jobs')->truncate();
    echo "SUCCESS: Queue jobs table truncated.\n";
} catch (\Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}
