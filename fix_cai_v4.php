<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$t = \App\Models\VpnTunnel::where('name', 'CAI')->first();
if ($t) {
    echo "Updating CAI Local ID to IP and DH 31...\n";
    $t->update([
        'local_id' => '20.82.165.228',
        'hash' => 'sha512',
        'dh_group' => 31
    ]);
    
    $vpnService = app(\App\Services\VpnControlService::class);
    $config = $vpnService->generateConfig($t);
    file_put_contents("/etc/swanctl/conf.d/CAI.conf", $config);
    $vpnService->reload();
    echo "Config reloaded.\n";
}
