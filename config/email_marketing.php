<?php

/*
 * Static config for the Email Marketing subsystem.
 * Per-deploy / per-tenant secrets live in the `settings` table
 * via the Setting singleton — see Admin → Marketing → Settings.
 */

return [

    // How many recipients each DispatchCampaignBatchJob processes.
    // Keep small so a single minute's tick can never overflow the
    // scheduler's withoutOverlapping window.
    'batch_size' => env('EMAIL_MARKETING_BATCH_SIZE', 50),

    // Absolute floor below the SES quota's MaxSendRate — if SES reports
    // a rate of 14/s but this is 5, the dispatcher uses 5.
    // Use null to defer entirely to the SES quota.
    'throttle_floor_per_second' => env('EMAIL_MARKETING_THROTTLE_FLOOR', null),

    // Default retention for email_events rows when no Setting override
    // is configured. Pruned daily.
    'event_retention_days_default' => 365,

    // Default expiry for opt-in tokens (days). Unsubscribe tokens never expire.
    'opt_in_token_ttl_days' => 30,

    // Spam-trigger words that surface a yellow warning on the campaign
    // builder. Not exhaustive — just the top offenders for inbox placement.
    'spam_trigger_words' => [
        'free', 'guarantee', 'no cost', 'no fees', 'risk-free',
        'winner', 'congratulations', 'urgent', 'act now', 'limited time',
        'click here', 'subscribe now', 'order now', 'cash bonus',
        '100% free', 'cheap', 'discount', 'lowest price', 'best price',
    ],

    // How long to cache the SES quota lookup. Quotas change rarely.
    'quota_cache_seconds' => 60,

    // Open/click tracking pixel + click-wrap URL configuration.
    // The tracking URL is built relative to the configuration set —
    // SES handles the redirect when click tracking is on.
    // We only generate our own pixel as a fallback when SES open
    // tracking is disabled by the admin.
    'fallback_open_pixel_path' => '/email/track/open/{token}.gif',
];
