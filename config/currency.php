<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base currency
    |--------------------------------------------------------------------------
    |
    | Every stored rate is "how many units of this currency equal 1 base unit".
    | Cross-rates (EGP -> SAR) are computed through the base, so there is one
    | number per currency to keep correct instead of one per pair.
    |
    */

    'base' => 'USD',

    /*
    |--------------------------------------------------------------------------
    | Default display currency
    |--------------------------------------------------------------------------
    |
    | Which currency the finance reports total into before anyone picks one.
    |
    */

    'display_default' => env('CURRENCY_DISPLAY_DEFAULT', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Indicative fallback rates
    |--------------------------------------------------------------------------
    |
    | Used ONLY when nobody has entered a rate for a currency at
    | /admin/itam/exchange-rates. They exist so a freshly deployed report shows
    | a combined total instead of a blank, and every total produced from them is
    | labelled "indicative — not reviewed" in the UI and in the CSV.
    |
    | These are NOT authoritative and are not maintained by anything. Nothing
    | fetches a live rate: the NOC's DNS is split-brain (see CLAUDE.md) and a
    | finance report that silently changes its own numbers between two runs is
    | worse than one that is openly out of date. Finance enters the rate it
    | actually books at, and that entry wins from then on.
    |
    | SAR is pegged to USD at 3.75 and has been for decades — that one is safe.
    | EGP floats and EUR moves daily; both need a human before use.
    |
    */

    'indicative_rates' => [
        'USD' => 1.0,
        'SAR' => 3.75,
        'EGP' => 48.0,
        'EUR' => 0.92,
    ],

];
