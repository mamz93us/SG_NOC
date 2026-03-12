<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$t = \App\Models\VpnTunnel::where('name', 'CAI')->first();
if ($t) {
    echo "CAI Details:\n";
    echo "PSK: " . $t->pre_shared_key . "\n";
    echo "Local ID: " . $t->local_id . "\n";
    echo "Remote ID: " . $t->remote_id . "\n";
    echo "Enc: " . $t->encryption . "\n";
    echo "Hash: " . $t->hash . "\n";
    echo "DH: " . $t->dh_group . "\n";
}
