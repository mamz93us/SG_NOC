<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Oracle attendance sender
    |--------------------------------------------------------------------------
    |
    | The class that hands an approved period to Oracle
    | (App\Services\Attendance\Oracle\OracleAttendanceSender). The stub stores
    | the payload for download and sends nothing; name the real HTTP sender
    | here once Oracle publishes its attendance API.
    |
    */

    'oracle_sender' => App\Services\Attendance\Oracle\StubOracleAttendanceSender::class,

];
