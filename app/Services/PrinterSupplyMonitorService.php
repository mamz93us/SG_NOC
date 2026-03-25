<?php

namespace App\Services;

use App\Models\Printer;
use App\Models\PrinterSupply;
use Illuminate\Support\Facades\Log;

class PrinterSupplyMonitorService
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Scan every printer's supplies and fire low-toner notifications.
     * Deduplicates via is_low_alert_active so only one alert fires per
     * low-toner event (resets once toner rises above threshold again).
     */
    public function checkAll(): void
    {
        $printers = Printer::with(['supplies', 'branch'])->get();

        foreach ($printers as $printer) {
            foreach ($printer->supplies as $supply) {
                if ($supply->supply_type === 'waste') {
                    continue; // waste containers not monitored here
                }

                $this->checkSupply($printer, $supply);
            }
        }
    }

    private function checkSupply(Printer $printer, PrinterSupply $supply): void
    {
        if ($supply->supply_percent === null) {
            return;
        }

        // Skip supplies that have never been polled (supply_percent is 0 but the record
        // was just created — no real data yet). We consider a supply "unpolled" when its
        // percent is 0 AND it has never fired an alert AND the record is less than 1 hour old.
        if ($supply->supply_percent === 0
            && $supply->low_alert_sent_at === null
            && $supply->created_at?->gt(now()->subHour())
        ) {
            return;
        }

        // Use per-supply override, otherwise fall back to warning_threshold, then default 10%
        $threshold = $supply->low_alert_threshold
            ?? $supply->warning_threshold
            ?? 10;

        $isLow = $supply->supply_percent <= $threshold;

        if ($isLow && ! $supply->is_low_alert_active) {
            // Toner just crossed below threshold for the first time — send alert
            $this->sendLowTonerAlert($printer, $supply);
            $supply->update([
                'is_low_alert_active' => true,
                'low_alert_sent_at'   => now(),
            ]);

        } elseif ($isLow && $supply->is_low_alert_active
                  && $supply->low_alert_sent_at?->lt(now()->subWeek())) {
            // Still low after 7 days — send a weekly reminder, update timestamp
            $this->sendLowTonerAlert($printer, $supply);
            $supply->update(['low_alert_sent_at' => now()]);

        } elseif (! $isLow && $supply->is_low_alert_active) {
            // Toner recovered above threshold — reset so future drop can notify again
            $supply->update(['is_low_alert_active' => false]);
        }
    }

    private function sendLowTonerAlert(Printer $printer, PrinterSupply $supply): void
    {
        $location = $printer->branch?->name ?? $printer->locationLabel() ?? 'Unknown location';
        $supplyName = ucwords(str_replace('_', ' ', $supply->supply_color ?? $supply->supply_descr ?? $supply->supply_type));
        $level = $supply->supply_percent;

        $title   = "Low Toner Alert: {$printer->printer_name}";
        $message = "Printer {$printer->printer_name} at {$location} has low toner "
                 . "({$supplyName}: {$level}%). Please replace soon.";
        $link = route('admin.printers.show', $printer->id);

        try {
            $this->notifications->notifyViaRules(
                type:     'printer_maintenance',
                title:    $title,
                message:  $message,
                link:     $link,
                severity: 'warning'
            );

            Log::info("PrinterSupplyMonitor: low toner alert sent", [
                'printer'  => $printer->printer_name,
                'supply'   => $supplyName,
                'level'    => $level,
                'threshold'=> $supply->low_alert_threshold ?? $supply->warning_threshold ?? 10,
            ]);
        } catch (\Throwable $e) {
            Log::error("PrinterSupplyMonitor: failed to notify", [
                'printer' => $printer->printer_name,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
