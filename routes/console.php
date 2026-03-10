<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ──────────────────────────────────────────────────────────────────────
// Load intervals from DB (with safe defaults if not yet configured).
// ──────────────────────────────────────────────────────────────────────
$settings = \App\Models\Setting::first();
$gdmsInterval     = max(1, (int) ($settings?->gdms_sync_interval     ?: 5));
$merakiInterval   = max(1, (int) ($settings?->meraki_polling_interval ?: 5));
$identityInterval = max(1, (int) ($settings?->identity_sync_interval  ?: 720));

// Helper: build cron expression for "every N minutes"
$everyN = fn(int $n): string => $n === 1 ? '* * * * *' : "*/{$n} * * * *";

// GDMS Contact Sync
Schedule::command('gdms:sync-contacts')
    ->cron($everyN($gdmsInterval))
    ->withoutOverlapping(15)
    ->runInBackground();

// Meraki Network Sync
Schedule::command('meraki:sync')
    ->cron($everyN($merakiInterval))
    ->withoutOverlapping(10)
    ->runInBackground();

// Azure / Entra ID Identity Sync
Schedule::command('identity:sync')
    ->cron($everyN($identityInterval))
    ->withoutOverlapping(30)
    ->runInBackground();

// Other internal jobs
Schedule::job(new \App\Jobs\RunNocAlertsJob)->everyFiveMinutes();
Schedule::job(new \App\Jobs\CheckLicenseMonitorsJob)->hourly();

// Warranty Expiry Check — weekly (runs inline)
Schedule::call(function () {
    try { (new \App\Jobs\CheckWarrantyExpiryJob)->handle(); } catch (\Throwable $e) {}
})->name('check-warranty-expiry')->withoutOverlapping(60)->weekly();

// Monitoring jobs run directly (not via queue) — shared hosting has no queue worker
Schedule::call(function () {
    try { (new \App\Jobs\CheckVpnStatusJob)->handle(); } catch (\Throwable $e) {}
})->name('check-vpn-status')->withoutOverlapping(5)->everyMinute();

Schedule::call(function () {
    $service = app(\App\Services\PingService::class);
    $hosts = \App\Models\MonitoredHost::where('ping_enabled', true)->get();
    foreach ($hosts as $host) {
        if ($host->last_ping_at && $host->last_ping_at->diffInSeconds(now()) < ($host->ping_interval_seconds ?? 60)) {
            continue;
        }
        try {
            $count = $host->ping_packet_count ?? 3;
            $result = $service->ping($host->ip, $count);
            \App\Models\HostCheck::create([
                'host_id' => $host->id, 'check_type' => 'ping',
                'latency_ms' => $result['latency'], 'packet_loss' => $result['packet_loss'],
                'success' => $result['success'],
            ]);
            $host->status = $result['success'] ? 'up' : 'down';
            $host->last_ping_at = now();
            $host->last_checked_at = now();
            $host->save();

            if (!$result['success']) {
                $event = \App\Models\NocEvent::firstOrCreate(
                    ['source_id' => $host->id, 'event_type' => 'host_down', 'status' => 'active'],
                    ['title' => "Host Down: {$host->name}", 'description' => "Ping failed for {$host->ip}.", 'severity' => 'critical', 'detected_at' => now()]
                );
                if ($event->wasRecentlyCreated && $host->alert_email) {
                    \Illuminate\Support\Facades\Notification::route('mail', $host->alert_email)
                        ->notify(new \App\Notifications\HostOfflineNotification($host));
                }
            } else {
                \App\Models\NocEvent::where('source_id', $host->id)->where('event_type', 'host_down')->where('status', 'active')
                    ->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        } catch (\Throwable $e) {}
    }
})->name('check-host-ping')->withoutOverlapping(2)->everyMinute();

// SNMP Metrics Collection — dispatched to queue for parallel processing
Schedule::call(function () {
    $hosts = \App\Models\MonitoredHost::where('snmp_enabled', true)
        ->where('status', '!=', 'down')
        ->get();
    foreach ($hosts as $host) {
        \App\Jobs\CollectSnmpMetricsJob::dispatch($host);
    }
})->name('collect-snmp-metrics')->everyMinute();

// ISP SLA Link Checks — every 5 minutes (runs inline)
Schedule::call(function () {
    $sla = app(\App\Services\SlaMonitorService::class);
    $isps = \App\Models\IspConnection::all();
    foreach ($isps as $isp) {
        try {
            $sla->checkLink($isp);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("SLA check failed for ISP #{$isp->id}: " . $e->getMessage());
        }
    }
})->name('check-isp-sla')->withoutOverlapping(5)->everyFiveMinutes();

// SNMP Device Discovery — once per day (runs inline)
Schedule::call(function () {
    $hosts = \App\Models\MonitoredHost::where('snmp_enabled', true)->get();
    foreach ($hosts as $host) {
        try {
            (new \App\Jobs\DiscoverSnmpDeviceJob($host))->handle();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("SNMP discover failed for {$host->ip}: " . $e->getMessage());
        }
    }
})->name('discover-snmp-devices')->withoutOverlapping(30)->daily();

// SNMP Interface Discovery — once per day (runs inline)
Schedule::call(function () {
    $hosts = \App\Models\MonitoredHost::where('snmp_enabled', true)->get();
    foreach ($hosts as $host) {
        try {
            (new \App\Jobs\DiscoverSnmpInterfacesJob($host))->handle();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("SNMP interface discover failed for {$host->ip}: " . $e->getMessage());
        }
    }
})->name('discover-snmp-interfaces')->withoutOverlapping(30)->daily();

// ──────────────────────────────────────────────────────────────────────
// Sophos Firewall Sync — configurable interval (runs inline)
// ──────────────────────────────────────────────────────────────────────
$sophosInterval = max(5, (int) ($settings?->sophos_sync_interval ?: 15));
Schedule::call(function () {
    $firewalls = \App\Models\SophosFirewall::where('sync_enabled', true)->get();
    foreach ($firewalls as $fw) {
        try {
            (new \App\Jobs\SyncSophosDataJob($fw))->handle();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Sophos sync failed for {$fw->name}: " . $e->getMessage());
        }
    }
})->name('sync-sophos-data')->withoutOverlapping(10)->cron($everyN($sophosInterval));

// ARP Table Collection (Sophos hosts) — every 10 minutes
Schedule::call(function () {
    $hosts = \App\Models\MonitoredHost::where('snmp_enabled', true)
        ->where('discovered_type', 'sophos')
        ->get();
    foreach ($hosts as $host) {
        try {
            (new \App\Jobs\CollectArpTableJob($host))->handle();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("ARP collection failed for {$host->ip}: " . $e->getMessage());
        }
    }
})->name('collect-arp-tables')->withoutOverlapping(10)->everyTenMinutes();

// DHCP Conflict Detection — every 10 minutes
Schedule::call(function () {
    try {
        (new \App\Jobs\DetectDhcpConflictsJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error("DHCP conflict detection failed: " . $e->getMessage());
    }
})->name('detect-dhcp-conflicts')->withoutOverlapping(10)->everyTenMinutes();
