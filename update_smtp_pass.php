<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Setting;

$s = Setting::get();
$s->smtp_password = 'gxqfdbmdfxhmmhgy';
$s->save();

echo "SUCCESS: SMTP Password updated and encrypted.\n";
echo "Decrypted verification: " . $s->smtp_password . "\n";
echo "Preview: " . substr($s->smtp_password, 0, 4) . "...\n";
