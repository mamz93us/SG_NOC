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
    | blocked: people Oracle filters out of its leave data at the database
    | level, so they are absent from every export and from both API endpoints
    | while still appearing in the employee list. They are not missing data and
    | not an import fault — they will simply never have a balance here.
    |
    | Naming them matters for more than the notes: an import withdraws held
    | records that its own rows do not mention, and a blocked person's records
    | are never mentioned by anything. Without this list, any import covering a
    | span they have leave in would stamp all of it "No longer in Oracle". That
    | is true of the spreadsheet path as it stands, not only of the API.
    | Verified 2026-09-20: ?personNumber=1655 returns [] on both vacation
    | endpoints while /employees returns the person.
    |
    */

    'books' => [
        'samirgroup' => [
            'label' => 'SamirGroup (Saudi Arabia)',
            'branches' => ['JED', 'RYD', 'KBR', 'ABH'],
            'weekend' => [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY],
            'blocked' => ['1655', '1656', '2682'],
        ],
    ],

    'default_book' => 'samirgroup',

    /*
    | Oracle accrues leave every month, so a balance older than this is shown
    | as out of date until a fresher export (or the API) replaces it.
    */
    'stale_after_days' => 35,

];
