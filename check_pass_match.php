<?php
require '/home/azureuser/phonebook2/vendor/autoload.php';
$app = require_once '/home/azureuser/phonebook2/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Setting;

$s = Setting::first();
$pass = $s->smtp_password;

if (!$pass) {
    echo "DB_PASS: EMPTY\n";
} else {
    echo "DB_PASS_LEN: " . strlen($pass) . "\n";
    echo "DB_PASS_START: " . substr($pass, 0, 2) . "\n";
    echo "DB_PASS_END: " . substr($pass, -2) . "\n";
}
