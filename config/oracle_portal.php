<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The book this feed carries
    |--------------------------------------------------------------------------
    |
    | The Employee Portal API serves ONE Oracle book: SamirGroup Saudi Arabia.
    | Every row it returns is in Riyadh, Jeddah, Al-Khobar or Abha — SSS Egypt
    | is not in it at all, and the two EMP_NO series collide, so a Cairo
    | employee holding the same number as a Saudi one must never be a candidate
    | for anything this feed drives.
    |
    | These are branch NAMES, as on the Branches page — `branches` has no code
    | column, and VacationLinker::branchIdsOf() matches the same list the same
    | way. Kept separate from config/vacations.php because a second book there
    | (an Egypt export) would not change what THIS API covers.
    |
    | An empty list, or names no branch has, would mean "every branch". The
    | vacation linker deliberately degrades that way so a stale config links
    | people rather than nobody. For a feed that suggests who has LEFT, the
    | same degradation would put every Egyptian employee in front of an admin
    | as a candidate leaver — so PortalBook::branchIds() refuses instead.
    |
    */

    'book' => [
        'key' => 'samirgroup',
        'label' => 'SamirGroup (Saudi Arabia)',
        'branches' => ['JED', 'RYD', 'KBR', 'ABH'],
    ],

    /*
    | The domain a new starter's UPN is built from when Oracle shows someone
    | the NOC has never held. It must be one of Settings ▸ Allowed Domains;
    | onboarding refuses to guess when it is blank or not on that list, and
    | the row waits for a human instead.
    */

    'default_upn_domain' => env('ORACLE_PORTAL_UPN_DOMAIN', 'samirgroup.com'),

    /*
    | The people Oracle filters out of its leave data live in
    | config/vacations.php under the book's `blocked` key, not here: an import
    | must not withdraw their held records, and that rule belongs to the
    | importer, which both the spreadsheet and this API go through. One list,
    | in the place that acts on it.
    */

    /*
    | Hire dates: Oracle writes them as dd-MMM-yy and the oldest in the feed is
    | from 1988, so the vacation importer's 2000-2100 window (right for leave,
    | which cannot be booked in 1995) would reject 21 people. The wider bound
    | lives on EmployeeFacts::MIN_HIRE_YEAR rather than here, because that class
    | is pure and a config lookup would make every test of it need a booted
    | application.
    */

];
