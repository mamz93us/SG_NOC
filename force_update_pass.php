<?php
require '/home/azureuser/phonebook2/vendor/autoload.php';
$app = require_once '/home/azureuser/phonebook2/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Setting;

try {
    $s = Setting::first();
    $s->smtp_password = 'gxqfdbmdfxhmmhgy'; // The correct password from the user
    $s->save();
    echo "SUCCESS: PASSWORD UPDATED IN DB\n";
    $final = $s->smtp_password;
    echo "VERIFY START: " . substr($final, 0, 2) . "\n";
    echo "VERIFY END: " . substr($final, -2) . "\n";
} catch (\Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}
