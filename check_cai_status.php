<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$t = \App\Models\VpnTunnel::where('name', 'CAI')->first();
echo "CAI Status in DB: " . ($t->status ?? 'NOT FOUND') . "\n";
echo "Last Checked: " . ($t->last_checked_at ?? 'NEVER') . "\n";
