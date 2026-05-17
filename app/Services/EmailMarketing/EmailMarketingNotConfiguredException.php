<?php

namespace App\Services\EmailMarketing;

class EmailMarketingNotConfiguredException extends \RuntimeException
{
    public static function missing(string $field): self
    {
        return new self("Email marketing is not fully configured: missing '{$field}'.");
    }

    public static function disabled(): self
    {
        return new self('Email marketing is disabled. Enable it in Admin → Marketing → Settings.');
    }
}
