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
    'local_subnet' => env('VPN_LOCAL_SUBNET', '10.0.0.0/16'),
];
