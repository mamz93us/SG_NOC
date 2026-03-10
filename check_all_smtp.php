<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Setting;

$s = Setting::get();
echo "Host: " . $s->smtp_host . "\n";
echo "Port: " . $s->smtp_port . "\n";
echo "Encryption: " . $s->smtp_encryption . "\n";
echo "User: " . $s->smtp_username . "\n";
echo "From Address: " . $s->smtp_from_address . "\n";
echo "From Name: " . $s->smtp_from_name . "\n";
echo "Password (decrypted): " . $s->smtp_password . "\n";
