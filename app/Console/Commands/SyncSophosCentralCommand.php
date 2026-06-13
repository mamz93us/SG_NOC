<?php

namespace App\Console\Commands;

use App\Models\NocEvent;
use App\Models\Setting;
use App\Models\SophosCentralAccessPoint;
use App\Models\SophosCentralFirewall;
use App\Models\SophosFirewall;
use App\Services\Sophos\SophosCentralService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class SyncSophosCentralCommand extends Command
{
    protected $signature = 'sophos-central:sync {--force : Run even if the integration is disabled in Settings}';

    protected $description = 'Sync access points, firewalls and alerts from Sophos Central into the NOC';

    public function handle(): int
    {
        $settings = Setting::get();

        if (! $settings->sophos_central_enabled && ! $this->option('force')) {
            $this->warn('Sophos Central integration is disabled in Settings.');

            return 0;
        }

        $service = new SophosCentralService($settings);

        if (! $service->isConfigured()) {
            $this->error('Sophos Central API credentials are not configured in Settings.');

            return 1;
        }

        $ok = true;
        $ok = $this->syncAccessPoints($service) && $ok;
        $ok = $this->syncFirewalls($service) && $ok;

        if ($settings->sophos_central_alerts_enabled) {
            $ok = $this->syncAlerts($service) && $ok;
        }

        $settings->sophos_central_last_sync_at = now();
        $settings->save();

        $this->info('Sophos Central sync finished.');

        return $ok ? 0 : 1;
    }

    // ─── Access points ────────────────────────────────────────────

    protected function syncAccessPoints(SophosCentralService $service): bool
    {
        try {
            $aps = $service->accessPoints();
        } catch (\Throwable $e) {
            // Sophos Central's public API has no AP-inventory listing endpoint
            // (the Wi-Fi Management API only exposes settings/mac-filtering + tasks).
            // Treat "not available" as a soft skip rather than a hard sync failure —
            // AP health still flows in via the wireless alerts ingested below.
            if ($this->isEndpointUnavailable($e)) {
                $this->warn('  · Access point inventory not available via Central API — skipping (AP issues still arrive as alerts).');

                return true;
            }

            $this->error('Access point sync failed: '.$e->getMessage());

            return false;
        }

        $seen = [];

        foreach ($aps as $ap) {
            $centralId = (string) ($ap['id'] ?? '');
            if ($centralId === '') {
                continue;
            }
            $seen[] = $centralId;

            $row = SophosCentralAccessPoint::updateOrCreate(
                ['central_id' => $centralId],
                [
                    'name' => Arr::get($ap, 'label') ?? Arr::get($ap, 'name'),
                    'serial_number' => Arr::get($ap, 'serialNumber') ?? Arr::get($ap, 'serial'),
                    'mac_address' => Arr::get($ap, 'macAddress') ?? Arr::get($ap, 'mac'),
                    'model' => Arr::get($ap, 'model') ?? Arr::get($ap, 'type') ?? Arr::get($ap, 'product'),
                    'firmware_version' => Arr::get($ap, 'firmwareVersion') ?? Arr::get($ap, 'firmware'),
                    'status' => $this->normalizeStatus(Arr::get($ap, 'status')),
                    'site_id' => Arr::get($ap, 'site.id') ?? Arr::get($ap, 'siteId'),
                    'site_name' => Arr::get($ap, 'site.name') ?? Arr::get($ap, 'siteName'),
                    'ip_address' => Arr::get($ap, 'ipAddress') ?? Arr::get($ap, 'ip'),
                    'central_last_seen_at' => $this->parseTime(Arr::get($ap, 'lastSeenAt') ?? Arr::get($ap, 'lastSeen')),
                    'raw' => $ap,
                ]
            );

            $this->reconcileDeviceEvent(
                sourceType: 'sophos_central_ap_offline',
                sourceId: $row->id,
                isDown: $row->isOffline(),
                isUp: $row->isOnline(),
                title: 'Sophos AP Offline: '.($row->name ?: $row->serial_number ?: $centralId),
                message: 'Access point '.($row->name ?: $centralId)
                    .($row->site_name ? " (site {$row->site_name})" : '')
                    .' is reported offline by Sophos Central.',
            );
        }

        // Drop rows for APs no longer present in Central (cache table)
        if ($seen !== []) {
            SophosCentralAccessPoint::whereNotIn('central_id', $seen)->delete();
        }

        $this->info('  ✓ Access points: '.count($seen));

        return true;
    }

    // ─── Firewalls ────────────────────────────────────────────────

    protected function syncFirewalls(SophosCentralService $service): bool
    {
        try {
            $firewalls = $service->firewalls();
        } catch (\Throwable $e) {
            $this->error('Firewall sync failed: '.$e->getMessage());

            return false;
        }

        $seen = [];

        foreach ($firewalls as $fw) {
            $centralId = (string) ($fw['id'] ?? '');
            if ($centralId === '') {
                continue;
            }
            $seen[] = $centralId;

            $serial = Arr::get($fw, 'serialNumber') ?? Arr::get($fw, 'serial');

            $row = SophosCentralFirewall::updateOrCreate(
                ['central_id' => $centralId],
                [
                    'name' => Arr::get($fw, 'name') ?? Arr::get($fw, 'hostname'),
                    'hostname' => Arr::get($fw, 'hostname'),
                    'serial_number' => $serial,
                    'model' => Arr::get($fw, 'model') ?? Arr::get($fw, 'product'),
                    'firmware_version' => Arr::get($fw, 'firmwareVersion') ?? Arr::get($fw, 'firmware.version'),
                    'status' => $this->normalizeStatus(Arr::get($fw, 'status')),
                    'group_name' => Arr::get($fw, 'group.name'),
                    'cluster_mode' => Arr::get($fw, 'cluster.mode'),
                    'available_firmware' => Arr::get($fw, 'availableFirmwareVersions') ?? Arr::get($fw, 'availableFirmware'),
                    'raw' => $fw,
                    // Match to a locally-managed firewall by serial when possible
                    'sophos_firewall_id' => $serial
                        ? SophosFirewall::where('serial_number', $serial)->value('id')
                        : null,
                ]
            );

            $this->reconcileDeviceEvent(
                sourceType: 'sophos_central_fw_disconnected',
                sourceId: $row->id,
                isDown: strtolower((string) $row->status) === 'disconnected',
                isUp: $row->isConnected(),
                title: 'Sophos Firewall Disconnected from Central: '.($row->name ?: $serial ?: $centralId),
                message: 'Firewall '.($row->name ?: $centralId)
                    .' has lost its connection to Sophos Central.',
            );
        }

        if ($seen !== []) {
            SophosCentralFirewall::whereNotIn('central_id', $seen)->delete();
        }

        $this->info('  ✓ Firewalls: '.count($seen));

        return true;
    }

    // ─── Central alerts → NOC events ──────────────────────────────

    protected function syncAlerts(SophosCentralService $service): bool
    {
        try {
            $alerts = $service->alerts();
        } catch (\Throwable $e) {
            $this->error('Alert sync failed: '.$e->getMessage());

            return false;
        }

        $openIds = [];

        foreach ($alerts as $alert) {
            $alertId = (string) ($alert['id'] ?? '');
            if ($alertId === '') {
                continue;
            }
            $openIds[] = $alertId;

            $product = Arr::get($alert, 'product', 'other');
            $raisedAt = $this->parseTime(Arr::get($alert, 'raisedAt')) ?? now();

            NocEvent::firstOrCreate(
                ['source_type' => 'sophos_central_alert', 'source_id' => $alertId, 'status' => 'open'],
                [
                    'module' => 'sophos',
                    'severity' => $this->mapSeverity(Arr::get($alert, 'severity')),
                    'title' => 'Sophos Central ['.$product.']: '.Arr::get($alert, 'type', 'alert'),
                    'message' => Arr::get($alert, 'description', 'Sophos Central alert'),
                    'first_seen' => $raisedAt,
                    'last_seen' => now(),
                ]
            )->update(['last_seen' => now()]);
        }

        // Resolve NOC events for alerts Central no longer reports as open
        NocEvent::where('source_type', 'sophos_central_alert')
            ->where('status', 'open')
            ->when($openIds !== [], fn ($q) => $q->whereNotIn('source_id', $openIds))
            ->get()
            ->each(fn ($ev) => $ev->update(['status' => 'resolved', 'resolved_at' => now()]));

        $this->info('  ✓ Central alerts: '.count($openIds).' open');

        return true;
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Raise an open NocEvent while a device is down, resolve it when the
     * device is confirmed back up. Unknown statuses leave events untouched.
     */
    protected function reconcileDeviceEvent(
        string $sourceType,
        int $sourceId,
        bool $isDown,
        bool $isUp,
        string $title,
        string $message,
    ): void {
        if ($isDown) {
            NocEvent::firstOrCreate(
                ['source_type' => $sourceType, 'source_id' => $sourceId, 'status' => 'open'],
                [
                    'module' => 'sophos',
                    'severity' => 'critical',
                    'title' => $title,
                    'message' => $message,
                    'first_seen' => now(),
                    'last_seen' => now(),
                ]
            )->update(['last_seen' => now()]);
        } elseif ($isUp) {
            NocEvent::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('status', 'open')
                ->get()
                ->each(fn ($ev) => $ev->update(['status' => 'resolved', 'resolved_at' => now()]));
        }
    }

    /**
     * Central APIs report status either as a plain string or as an object
     * (e.g. {"connected": true}) — normalize to a lowercase string.
     */
    protected function normalizeStatus(mixed $status): ?string
    {
        if (is_array($status)) {
            if (array_key_exists('connected', $status)) {
                return $status['connected'] ? 'connected' : 'disconnected';
            }
            $status = Arr::get($status, 'status') ?? Arr::get($status, 'state');
        }

        return is_string($status) && $status !== '' ? strtolower($status) : null;
    }

    /**
     * Detect the Sophos APIGW "route/application not found" responses so a
     * missing product endpoint is a soft skip, not a hard sync failure.
     */
    protected function isEndpointUnavailable(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'HTTP 404')
            || str_contains($msg, 'ApplicationNotFound')
            || str_contains($msg, 'Unable to identify proxy');
    }

    protected function parseTime(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function mapSeverity(?string $severity): string
    {
        return match (strtolower((string) $severity)) {
            'high' => 'critical',
            'medium' => 'warning',
            'low' => 'info',
            default => 'warning',
        };
    }
}
