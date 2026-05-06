<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FreeRADIUS Control Wrapper
    |--------------------------------------------------------------------------
    |
    | Path to the sudo-allowed shell script that triggers FreeRADIUS reloads
    | from the Laravel admin UI. Must be in /etc/sudoers.d/sg-radius for the
    | webserver user to invoke without a password.
    |
    | Subcommands the wrapper accepts (see deployment/freeradius/sg-radius-control.sh):
    |   reload-clients   — re-reads radius_nas_clients via radmin
    |   restart          — full systemctl restart freeradius (rare)
    |
    */

    'control_script' => env('RADIUS_CONTROL_SCRIPT', '/usr/local/bin/sg-radius-control'),

];
