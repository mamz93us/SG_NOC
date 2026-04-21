<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\NocEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckWarrantyExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Find devices whose warranty expires within 30 days
        $expiringSoon = Device::whereNotNull('warranty_expiry')
            ->whereBetween('warranty_expiry', [now(), now()->addDays(30)])
            ->get();

        foreach ($expiringSoon as $device) {
            $daysLeft = $device->warrantyDaysLeft();

            NocEvent::firstOrCreate(
                [
                    'source_type' => 'warranty',
                    'source_id'   => $device->id,
                    'entity_type' => 'warranty_expiring',
                    'status'      => 'open',
                ],
                [
                    'module'     => 'assets',
                    'entity_id'  => (string) $device->id,
                    'title'      => "Warranty Expiring: {$device->name}",
                    'message'    => "Device \"{$device->name}\" (S/N: {$device->serial_number}) warranty expires in {$daysLeft} days ({$device->warranty_expiry->format('Y-m-d')}).",
                    'severity'   => $daysLeft <= 7 ? 'critical' : 'warning',
                    'first_seen' => now(),
                    'last_seen'  => now(),
                ]
            );
        }

        // Find already expired devices (only alert once via firstOrCreate)
        $expired = Device::whereNotNull('warranty_expiry')
            ->where('warranty_expiry', '<', now())
            ->get();

        foreach ($expired as $device) {
            NocEvent::firstOrCreate(
                [
                    'source_type' => 'warranty',
                    'source_id'   => $device->id,
                    'entity_type' => 'warranty_expired',
                    'status'      => 'open',
                ],
                [
                    'module'     => 'assets',
                    'entity_id'  => (string) $device->id,
                    'title'      => "Warranty Expired: {$device->name}",
                    'message'    => "Device \"{$device->name}\" (S/N: {$device->serial_number}) warranty expired on {$device->warranty_expiry->format('Y-m-d')}.",
                    'severity'   => 'info',
                    'first_seen' => now(),
                    'last_seen'  => now(),
                ]
            );
        }
    }
}
