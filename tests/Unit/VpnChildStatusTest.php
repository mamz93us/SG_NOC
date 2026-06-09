<?php

use App\Models\VpnTunnel;
use App\Services\VpnControlService;

// Pure parsing/model logic — no DB, so this runs even though the wider
// Feature suite is broken under SQLite.

$SAS = <<<'OUT'
JED: #23, ESTABLISHED, IKEv2, 7ddaa40e098992b1_i ce2a67d0ad119c1d_r*
  local  'noc.samirgroup.net' @ 172.22.0.4[4500]
  remote '212.11.160.226' @ 212.11.160.226[4500]
  AES_CBC-256/HMAC_SHA2_512_256/PRF_HMAC_SHA2_512/CURVE_25519
  established 0s ago, rekeying in 28570s
  JED: #28, reqid 1, INSTALLED, TUNNEL-in-UDP, ESP:AES_CBC-256/HMAC_SHA2_256_128
    installed 10s ago, rekeying in 100s, expires in 200s
    in  c1b58e17, 1494183 bytes,  2532 packets,     0s ago
    out cc85eec5, 156746 bytes,   496 packets,     0s ago
    local  172.22.0.0/24
    remote 10.1.0.0/22
KBR: #17, ESTABLISHED, IKEv2, fc6d22f2ea9b30a1_i f67527ed66a1fb83_r*
  local  'noc.samirgroup.net' @ 172.22.0.4[4500]
  remote '212.11.160.222' @ 212.11.160.222[4500]
  KBR: #24, reqid 1, INSTALLED, TUNNEL-in-UDP, ESP:AES_CBC-256/HMAC_SHA2_256_128
    in  c9dffd20, 40458 bytes, 304 packets, 2s ago
    out c687b7fc, 46562 bytes, 288 packets, 2s ago
    local  172.22.0.0/24
    remote 10.3.0.0/24
OUT;

it('parses installed child SAs for the requested tunnel only', function () use ($SAS) {
    $children = (new VpnControlService)->parseChildren($SAS, 'JED');

    expect($children)->toHaveCount(1)
        ->and($children)->toHaveKey('172.22.0.0/24|10.1.0.0/22');

    $c = $children['172.22.0.0/24|10.1.0.0/22'];
    expect($c['state'])->toBe('INSTALLED')
        ->and($c['local_ts'])->toBe('172.22.0.0/24')
        ->and($c['remote_ts'])->toBe('10.1.0.0/22')
        ->and($c['bytes_in'])->toBe(1494183)
        ->and($c['bytes_out'])->toBe(156746);
});

it('expectedChildren mirrors generateConfig naming and the subnet cross-product', function () {
    $t = new VpnTunnel([
        'name' => 'JED',
        'local_subnet' => '172.22.0.0/24',
        'remote_subnet' => '10.1.0.0/22, 10.1.8.0/24',
    ]);

    $children = $t->expectedChildren();

    expect($children)->toHaveCount(2)
        ->and($children[0])->toMatchArray(['name' => 'JED', 'local_ts' => '172.22.0.0/24', 'remote_ts' => '10.1.0.0/22'])
        ->and($children[1])->toMatchArray(['name' => 'JED-2', 'local_ts' => '172.22.0.0/24', 'remote_ts' => '10.1.8.0/24']);
});

it('marks a configured child down when it is not in the SA list', function () use ($SAS) {
    $t = new VpnTunnel([
        'name' => 'JED',
        'local_subnet' => '172.22.0.0/24',
        'remote_subnet' => '10.1.0.0/22, 10.1.8.0/24',
    ]);

    $installed = (new VpnControlService)->parseChildren($SAS, 'JED');

    $status = collect($t->expectedChildren())->map(fn ($c) => [
        'remote_ts' => $c['remote_ts'],
        'up' => isset($installed[$c['local_ts'].'|'.$c['remote_ts']]),
    ])->keyBy('remote_ts');

    expect($status['10.1.0.0/22']['up'])->toBeTrue()   // installed
        ->and($status['10.1.8.0/24']['up'])->toBeFalse(); // missing → down
});
