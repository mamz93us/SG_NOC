<?php

use Carbon\CarbonInterface;

return [

    /*
    |--------------------------------------------------------------------------
    | Books
    |--------------------------------------------------------------------------
    |
    | One Oracle export per legal employer. Oracle numbers collide between the
    | SSS Egypt and SamirGroup books, so an import is always for one book, and
    | a number two NOC employees hold is settled by the book's branches (the
    | branch NAMES, as on the Branches page). With no branches listed, every
    | employee holding the number is a candidate.
    |
    | weekend: the days an absence does not use up, counted into each record's
    | work days. The SamirGroup sheet confirms Friday and Saturday — no annual
    | leave in it starts on either, and its used days match that count.
    |
    */

    'books' => [
        'samirgroup' => [
            'label' => 'SamirGroup (Saudi Arabia)',
            'branches' => ['JED', 'RYD', 'KBR', 'ABH'],
            'weekend' => [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY],
        ],
    ],

    'default_book' => 'samirgroup',

    /*
    | Oracle accrues leave every month, so a balance older than this is shown
    | as out of date until a fresher export (or the API) replaces it.
    */
    'stale_after_days' => 35,

];
