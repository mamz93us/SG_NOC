<?php

return [

    'page_title' => 'My Tickets | Samir Group Employee Portal',
    'heading' => 'My Tickets',
    'subheading' => 'Everything you have raised with IT, and where each one stands.',
    'back_to_portal' => 'Back to portal',

    'stats' => [
        'still_open' => 'Still open',
        'still_open_sub' => 'Being worked on, or waiting for you',
        'all_time' => 'All time',
        'all_time_sub' => 'Tickets you have raised',
        'needs_you' => 'Needs you',
        'completed' => 'Completed',
    ],

    'filters' => [
        'all' => 'All',
    ],

    // Keyed by App\Services\Ticketing\TicketStatus's own constant values
    // (OPEN=1 .. REJECTED=7), not by the label text the ticketing API
    // returns — that way the filter chips stay bilingual without touching
    // that service class.
    'status' => [
        1 => 'Open',
        2 => 'In Progress',
        3 => 'Waiting for your action',
        4 => 'Completed',
        5 => 'Cancelled',
        6 => 'Closed',
        7 => 'Rejected',
    ],

    'empty' => [
        'none_raised' => 'You have not raised any tickets yet.',
        'none_with_status' => 'Nothing with this status.',
    ],

    'item' => [
        'raised' => 'Raised :date',
        'with_engineer' => 'With :name',
    ],

];
