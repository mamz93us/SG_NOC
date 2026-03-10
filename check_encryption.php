<?php
require '/home/azureuser/phonebook2/vendor/autoload.php';
$app = require_once '/home/azureuser/phonebook2/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

try {
    $s = Setting::first();
    echo "DB_ID: " . $s->id . "\n";
    echo "DB_USER: " . $s->smtp_username . "\n";
    $raw = $s->getAttributes()['smtp_password'] ?? 'EMPTY';
    echo "ENCRYPTED_LEN: " . strlen($raw) . "\n";
    
    $decrypted = $s->smtp_password; // This triggers the getter
    if ($decrypted === null) {
        echo "DECRYPTION FAILED (Getter returned null)\n";
        // Let's try manual decryption to see the error
        try {
            Crypt::decryptString($raw);
        } catch (\Exception $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    } else {
        echo "DECRYPTION SUCCESS (Len: " . strlen($decrypted) . ")\n";
    }
} catch (\Exception $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
}
