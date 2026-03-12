<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach(\App\Models\VpnTunnel::all() as $t) {
    echo "Name: " . $t->name . "\n";
    echo "  Gateway: " . $t->remote_public_ip . "\n";
    echo "  Status: " . $t->status . "\n";
    echo "-------------------\n";
}
