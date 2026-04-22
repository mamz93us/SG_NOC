<?php

namespace App\Jobs;

use App\Models\License;
use App\Models\NocEvent;
use App\Models\SslCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Daily scan for expiring software licenses and SSL certificates.
 *
 * For each item expiring inside the warning window (or already expired),
 * firstOrCreate an open NocEvent so the NOC dashboard + notification
 * rules pick it up without duplicating alerts.
 */
class CheckExpiryAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 1;

    private const LICENSE_WARN_DAYS = 30;
    private const SSL_WARN_DAYS     = 14;

    public function handle(): void
    {
        $this->checkLicenses();
        $this->checkSslCertificates();
    }

    private function checkLicenses(): void
    {
        $now = now();

        $expiring = License::whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$now, $now->copy()->addDays(self::LICENSE_WARN_DAYS)])
            ->get();

        foreach ($expiring as $license) {
            $daysLeft = (int) $now->diffInDays($license->expiry_date, false);

            NocEvent::firstOrCreate(
                [
                    'source_type' => 'license',
                    'source_id'   => $license->id,
                    'event_type'  => 'license_expiring',
                    'status'      => 'open',
                ],
                [
                    'module'      => 'asset',
                    'title'       => "License Expiring: {$license->license_name}",
                    'description' => "License \"{$license->license_name}\" (vendor: " . ($license->vendor ?? 'n/a') . ") expires in {$daysLeft} days ({$license->expiry_date->format('Y-m-d')}).",
                    'severity'    => $daysLeft <= 7 ? 'critical' : 'warning',
                    'detected_at' => now(),
                    'last_seen'   => now(),
                ]
            );
        }

        $expired = License::whereNotNull('expiry_date')
            ->where('expiry_date', '<', $now)
            ->get();

        foreach ($expired as $license) {
            NocEvent::firstOrCreate(
                [
                    'source_type' => 'license',
                    'source_id'   => $license->id,
                    'event_type'  => 'license_expired',
                    'status'      => 'open',
                ],
                [
                    'module'      => 'asset',
                    'title'       => "License Expired: {$license->license_name}",
                    'description' => "License \"{$license->license_name}\" expired on {$license->expiry_date->format('Y-m-d')}.",
                    'severity'    => 'warning',
                    'detected_at' => now(),
                    'last_seen'   => now(),
                ]
            );
        }
    }

    private function checkSslCertificates(): void
    {
        $now = now();

        // Warn on non-auto-renewing certs; auto-renew handles itself via
        // RenewExpiringCertificatesJob at 02:00. Also alert if auto-renew is
        // on but expiry is dangerously close (renewal may have failed silently).
        $expiring = SslCertificate::whereNotNull('expires_at')
            ->where('status', 'valid')
            ->whereBetween('expires_at', [$now, $now->copy()->addDays(self::SSL_WARN_DAYS)])
            ->get();

        foreach ($expiring as $cert) {
            $daysLeft = (int) $now->diffInDays($cert->expires_at, false);

            // Skip auto-renewing certs with plenty of headroom (>7 days).
            if ($cert->auto_renew && $daysLeft > 7) {
                continue;
            }

            NocEvent::firstOrCreate(
                [
                    'source_type' => 'ssl_certificate',
                    'source_id'   => $cert->id,
                    'event_type'  => 'ssl_expiring',
                    'status'      => 'open',
                ],
                [
                    'module'      => 'network',
                    'title'       => "SSL Expiring: {$cert->fqdn}",
                    'description' => "Certificate for {$cert->fqdn} expires in {$daysLeft} day(s) on {$cert->expires_at->format('Y-m-d')}."
                                   . ($cert->auto_renew ? ' Auto-renew is enabled but has not completed — check RenewExpiringCertificatesJob logs.' : ' Auto-renew is OFF.'),
                    'severity'    => $daysLeft <= 3 ? 'critical' : 'warning',
                    'detected_at' => now(),
                    'last_seen'   => now(),
                ]
            );
        }

        $expired = SslCertificate::whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->whereIn('status', ['valid', 'expired'])
            ->get();

        foreach ($expired as $cert) {
            NocEvent::firstOrCreate(
                [
                    'source_type' => 'ssl_certificate',
                    'source_id'   => $cert->id,
                    'event_type'  => 'ssl_expired',
                    'status'      => 'open',
                ],
                [
                    'module'      => 'network',
                    'title'       => "SSL Expired: {$cert->fqdn}",
                    'description' => "Certificate for {$cert->fqdn} expired on {$cert->expires_at->format('Y-m-d')}.",
                    'severity'    => 'critical',
                    'detected_at' => now(),
                    'last_seen'   => now(),
                ]
            );
        }
    }
}
