<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VPN Hub Configuration
    |--------------------------------------------------------------------------
    |
    | default_local_subnet: The subnet of the VM where strongSwan is running.
    | This is used to auto-populate the local traffic selector in VPN tunnels.
    |
    */
    // The Azure VNet the NOC VM lives in (VPS = 172.22.0.4). MUST match what each
    // branch Sophos has configured as its IPsec "remote/peer network", or phase-2
    // negotiation fails with TS_UNACCEPTABLE. 10.0.0.0/16 was wrong — it doesn't
    // even contain the VM's own address.
    'local_subnet' => env('VPN_LOCAL_SUBNET', '172.22.0.0/24'),
    'local_id'     => env('VPN_LOCAL_ID', 'noc.samirgroup.net'),
];
