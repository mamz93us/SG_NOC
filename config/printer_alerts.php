<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default thresholds (overridden per branch via PrinterBranchSetting)
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        'toner_warning' => env('PRINTER_TONER_WARNING_DEFAULT', 20),
        'toner_critical' => env('PRINTER_TONER_CRITICAL_DEFAULT', 5),
        // Waste-container "remaining capacity" threshold. When toner_waste
        // (SNMP "remaining %" semantics) drops to or below this value, the
        // container is considered full and alerts fire.
        'waste_critical' => env('PRINTER_WASTE_CRITICAL_DEFAULT', 5),
        // Hysteresis added when auto-resolving. Toner must recover above
        // (warning_threshold + hysteresis) before the open event closes.
        'recovery_hysteresis' => env('PRINTER_ALERT_HYSTERESIS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback recipients
    |--------------------------------------------------------------------------
    | Used by SendPrinterAlertEmailJob when a printer has no branch_id, no
    | PrinterBranchSetting, or no active recipients configured. Comma-separated
    | list of emails. Never silently drop alerts.
    */
    'fallback_emails' => array_filter(array_map(
        'trim',
        explode(',', (string) env('PRINTER_ALERTS_FALLBACK_EMAILS', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Toner email mode
    |--------------------------------------------------------------------------
    | 'immediate'      → one email per low-toner condition, as it happens
    |                    (the original behaviour).
    | 'monthly_digest' → suppress the per-cartridge toner/waste emails and
    |                    instead send ONE consolidated low-toner report once a
    |                    month. Printer *errors* (jam/offline/door) and paper-low
    |                    alerts are unaffected — they still email immediately.
    |
    | This only changes TONER/supply emails. The in-app NOC events are still
    | created so the dashboard stays accurate.
    */
    'toner_email_mode' => env('PRINTER_TONER_EMAIL_MODE', 'monthly_digest'),

    'digest' => [
        // Day of month (1-28) and time the monthly digest is sent.
        'day' => (int) env('PRINTER_TONER_DIGEST_DAY', 1),
        'time' => env('PRINTER_TONER_DIGEST_TIME', '08:00'),

        // Extra recipients for the digest (comma-separated). The digest also
        // goes to every branch's active alert recipients + manager, and falls
        // back to admins when nothing is configured.
        'emails' => array_filter(array_map(
            'trim',
            explode(',', (string) env('PRINTER_TONER_DIGEST_EMAILS', ''))
        )),
    ],
];
