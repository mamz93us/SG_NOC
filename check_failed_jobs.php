<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$failed = DB::table('failed_jobs')->latest('failed_at')->get();

echo "FAILED JOBS:\n";
foreach($failed as $job) {
    echo "ID: " . $job->id . " | Job: " . $job->uuid . "\n";
    echo "Failing at: " . $job->failed_at . "\n";
    // echo "Exception: " . substr($job->exception, 0, 200) . "...\n";
    if (str_contains($job->exception, 'Authentication unsuccessful')) {
        echo "AUTH ERROR (SMTP)\n";
    } else {
        echo "ERROR: " . substr($job->exception, 0, 300) . "\n";
    }
    echo "---------------------------------\n";
}
