<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$t = \App\Models\VpnTunnel::where('name', 'CAI')->first();
if ($t) {
    echo "Regenerating CAI config with clean parameters...\n";
    // Ensure parameters are correct
    $t->update([
        'local_id' => '20.82.165.228', // The logs showed success with this
        'hash' => 'sha512',
        'dh_group' => 31 // Curve25519
    ]);
    
    $vpnService = app(\App\Services\VpnControlService::class);
    $config = $vpnService->generateConfig($t);
    echo "Generated config length: " . strlen($config) . "\n";
    
    // Direct write to be sure
    $path = "/etc/swanctl/conf.d/CAI.conf";
    if (file_put_contents($path, $config)) {
        echo "Config saved to $path\n";
    } else {
        echo "Failed to save config to $path\n";
    }
    
    $vpnService->reload();
    echo "Swanctl reloaded.\n";
    
    // Initiate
    passthru("sudo swanctl --terminate --ike CAI");
    sleep(1);
    passthru("sudo swanctl --initiate --child CAI");
}
