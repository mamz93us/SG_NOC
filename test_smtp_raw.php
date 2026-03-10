<?php
require '/home/azureuser/phonebook2/vendor/autoload.php';
$app = require_once '/home/azureuser/phonebook2/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Setting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;

$settings = Setting::first();
echo "Testing SMTP for: " . $settings->smtp_username . "\n";
echo "Host: " . $settings->smtp_host . "\n";
echo "Port: " . $settings->smtp_port . "\n";
echo "Encryption: " . $settings->smtp_encryption . "\n";

Config::set('mail.default', 'smtp');
Config::set('mail.mailers.smtp.host', $settings->smtp_host);
Config::set('mail.mailers.smtp.port', $settings->smtp_port ?? 587);
Config::set('mail.mailers.smtp.encryption', $settings->smtp_encryption === 'none' ? null : ($settings->smtp_encryption ?? 'tls'));
Config::set('mail.mailers.smtp.username', $settings->smtp_username);
Config::set('mail.mailers.smtp.password', $settings->smtp_password);
Config::set('mail.from.address', $settings->smtp_from_address);
Config::set('mail.from.name', $settings->smtp_from_name);

try {
    Mail::raw('Raw SMTP test', function ($message) {
        $message->to('mohamed.zahran@sssegypt.com')->subject('Raw SMTP Test');
    });
    echo "SUCCESS\n";
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    if ($e->getPrevious()) {
        echo "PREVIOUS: " . $e->getPrevious()->getMessage() . "\n";
    }
}
