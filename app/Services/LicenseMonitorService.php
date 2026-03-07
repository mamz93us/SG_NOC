<?php

namespace App\Services;

use App\Models\IdentityLicense;
use App\Models\LicenseMonitor;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\Log;

class LicenseMonitorService
{
    public function __construct(
        private WorkflowEngine      $engine,
        private NotificationService $notifications
    ) {}

    public function checkAllMonitors(): void
    {
        $monitors = LicenseMonitor::where('is_active', 1)->get();

        foreach ($monitors as $monitor) {
            try {
                $this->checkMonitor($monitor);
            } catch (\Throwable $e) {
                Log::error("LicenseMonitorService: error checking monitor #{$monitor->id}: " . $e->getMessage());
            }
        }
    }

    public function checkMonitor(LicenseMonitor $monitor): void
    {
        $license = IdentityLicense::where('sku_id', $monitor->sku_id)->first();

        if (! $license) {
            Log::warning("LicenseMonitorService: license SKU {$monitor->sku_id} not found.");
            return;
        }

        // 'available' may be a computed column: total enabled - consumed
        $available = $license->available ?? max(0, ($license->enabled ?? 0) - ($license->consumed ?? 0));

        if ($available <= $monitor->critical_threshold && $monitor->canAlert()) {
            $this->createPurchaseWorkflow($monitor, $license, $available);
            $monitor->update(['last_alerted_at' => now()]);
        }
    }

    private function createPurchaseWorkflow(LicenseMonitor $monitor, IdentityLicense $license, int $available): void
    {
        try {
            $this->engine->createRequest(
                type: 'license_purchase',
                payload: [
                    'sku_id'             => $license->sku_id,
                    'sku_part_number'    => $license->sku_part_number ?? '',
                    'display_name'       => $license->display_name,
                    'available'          => $available,
                    'consumed'           => $license->consumed   ?? 0,
                    'enabled'            => $license->enabled    ?? 0,
                    'critical_threshold' => $monitor->critical_threshold,
                ],
                branchId:    null,
                requestedBy: null,
                title:       "License Purchase Required: {$license->display_name}",
                description: "Available seats ({$available}) have reached the critical threshold ({$monitor->critical_threshold})."
            );

            $this->notifications->notifyAdmins(
                'system_alert',
                'Low License Alert',
                "License '{$license->display_name}' has only {$available} seat(s) left (threshold: {$monitor->critical_threshold}). A purchase workflow has been created.",
                route('admin.license-monitors.index'),
                'warning'
            );

            Log::info("LicenseMonitorService: purchase workflow created for {$license->display_name}.");

        } catch (\Throwable $e) {
            Log::error("LicenseMonitorService: failed to create purchase workflow for {$license->display_name}: " . $e->getMessage());
        }
    }
}
