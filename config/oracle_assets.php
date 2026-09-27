<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Branches the register's employee numbers belong to
    |--------------------------------------------------------------------------
    |
    | Oracle's fixed-asset register is SamirGroup's (Saudi Arabia): Cairo and
    | SSS Egypt are not in it. Employee numbers collide between the SSS Egypt
    | and SamirGroup series, so a number is only linked to an employee in these
    | branches (the branch NAMES, as on the Branches page) or with no branch.
    | A number held only by someone in another branch is somebody else.
    |
    */

    'branches' => ['JED', 'RYD', 'KBR', 'ABH'],

    /*
    |--------------------------------------------------------------------------
    | Largest file the import page accepts, in kilobytes
    |--------------------------------------------------------------------------
    */

    'max_upload_kb' => 20480,

];
