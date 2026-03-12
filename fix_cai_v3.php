<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$t = \App\Models\VpnTunnel::where('name', 'CAI')->first();
if ($t) {
    echo "Updating CAI to DH 31 (Curve25519) and SHA-512...\n";
    $t->update([
        'hash' => 'sha512',
        'dh_group' => 31 // Curve25519
    ]);
    
    $vpnService = app(\App\Services\VpnControlService::class);
    $config = $vpnService->generateConfig($t);
    file_put_contents("/etc/swanctl/conf.d/CAI.conf", $config);
    $vpnService->reload();
    echo "Config reloaded with Curve25519.\n";
}
