<?php

namespace App\Support;

class Currency
{
    // EUR is here for the AI subscriptions billed out of the EU (Magnific).
    // Order is display order in the licence form.
    public const CODES = ['EGP', 'SAR', 'USD', 'EUR'];

    public const DEFAULT = 'USD';
}
