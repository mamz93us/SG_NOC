<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default thresholds (overridden per branch via PrinterBranchSetting)
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        'toner_warning'   => env('PRINTER_TONER_WARNING_DEFAULT', 20),
        'toner_critical'  => env('PRINTER_TONER_CRITICAL_DEFAULT', 5),
        // Waste-container "remaining capacity" threshold. When toner_waste
        // (SNMP "remaining %" semantics) drops to or below this value, the
        // container is considered full and alerts fire.
        'waste_critical'  => env('PRINTER_WASTE_CRITICAL_DEFAULT', 5),
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
];
