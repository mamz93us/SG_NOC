<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\SmtpConfigService;

$service = new SmtpConfigService();
try {
    echo "Sending test email to noc@samirgroup.com...\n";
    $res = $service->sendTestEmail('noc@samirgroup.com');
    if ($res) {
        echo "SUCCESS! Email sent.\n";
    }
} catch (\Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
