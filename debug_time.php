<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "PHP Time: " . now()->toDateTimeString() . "\n";
echo "Config Timezone: " . config('app.timezone') . "\n";
echo "DB Time: " . DB::select('SELECT NOW() as now')[0]->now . "\n";
