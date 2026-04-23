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

// CUPS Print Manager — status refresh
$cupsInterval = max(1, (int) ($settings?->cups_refresh_interval ?: 5));
Schedule::command('cups:refresh-status')
    ->cron($everyN($cupsInterval))
    ->withoutOverlapping(5)
    ->runInBackground();

// Other internal jobs
Schedule::job(new \App\Jobs\RunNocAlertsJob)->everyFiveMinutes();
Schedule::job(new \App\Jobs\CheckLicenseMonitorsJob)->hourly();

// Daily expiry scan — software licenses (ITAM) and SSL certificates.
// Raises NocEvents so the existing notification rules pick them up.
Schedule::call(function () {
    try { (new \App\Jobs\CheckExpiryAlertsJob)->handle(); } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Expiry alerts check failed: ' . $e->getMessage());
    }
})->name('check-expiry-alerts')->withoutOverlapping(30)->dailyAt('07:00');

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

// SNMP Metrics Collection — runs inline (NOT queued) to avoid flooding the queue
// Each host takes ~40-50s, so we run them sequentially every 2 minutes
Schedule::call(function () {
    $hosts = \App\Models\MonitoredHost::where('snmp_enabled', true)
        ->where('status', '!=', 'down')
        ->get();
    foreach ($hosts as $host) {
        try {
            (new \App\Jobs\CollectSnmpMetricsJob($host))->handle();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("SNMP metrics failed for {$host->ip}: " . $e->getMessage());
        }
    }
})->name('collect-snmp-metrics')->withoutOverlapping(5)->everyTwoMinutes();

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

// ──────────────────────────────────────────────────────────────────────
// UCM Extension / Trunk / Active Call Sync + Phone-Port Correlation
// ──────────────────────────────────────────────────────────────────────

// UCM Extensions + Trunks — every 20 seconds (runs inline to avoid queue dependency)
Schedule::call(function () {
    try { (new \App\Jobs\SyncUcmExtensionsJob)->handle(); } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error("UCM extension sync failed: " . $e->getMessage());
    }
})->name('sync-ucm-extensions')->withoutOverlapping(15)->everyFifteenSeconds();

// UCM Active Calls — every 15 seconds (runs inline)
Schedule::call(function () {
    try { (new \App\Jobs\SyncUcmActiveCallsJob)->handle(); } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error("UCM active calls sync failed: " . $e->getMessage());
    }
})->name('sync-ucm-active-calls')->withoutOverlapping(10)->everyFifteenSeconds();

// Phone-Port MAC Correlation — every 60 seconds (runs inline)
Schedule::call(function () {
    try { (new \App\Jobs\SyncPhonePortMappingJob)->handle(app(\App\Services\PhonePortDetectionService::class)); } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error("Phone-port mapping failed: " . $e->getMessage());
    }
})->name('sync-phone-port-map')->withoutOverlapping(30)->everyMinute();

// ──────────────────────────────────────────────────────────────────────
// ISP Renewal Reminders — daily at 8 AM
// ──────────────────────────────────────────────────────────────────────
Schedule::call(function () {
    $isps = \App\Models\IspConnection::whereNotNull('renewal_date')->get();

    foreach ($isps as $isp) {
        if (!$isp->needsRenewalReminder()) {
            continue;
        }

        // Find recipients from notification rules, or fall back to admins
        $recipients = collect();

        $rules = \App\Models\NotificationRule::active()
            ->forEvent('isp_renewal')
            ->where('send_email', true)
            ->get();

        foreach ($rules as $rule) {
            if ($rule->recipient_type === 'user' && $rule->recipientUser) {
                $recipients->push($rule->recipientUser);
            } elseif ($rule->recipient_type === 'role' && $rule->recipient_role) {
                $users = \App\Models\User::role($rule->recipient_role)->get();
                $recipients = $recipients->merge($users);
            }
        }

        // Fallback: notify all admins if no rules configured
        if ($recipients->isEmpty()) {
            $recipients = \App\Models\User::role('admin')->get();
        }

        // Fallback: notify first user if no admins
        if ($recipients->isEmpty()) {
            $first = \App\Models\User::first();
            if ($first) $recipients->push($first);
        }

        $recipients->unique('id')->each(function ($user) use ($isp) {
            try {
                $user->notify(new \App\Notifications\IspRenewalReminderNotification($isp));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("ISP renewal notification failed for ISP #{$isp->id}: " . $e->getMessage());
            }
        });

        // Mark as reminded so we don't spam
        $isp->update(['renewal_reminded_at' => now()]);

        // Also create in-app notification
        try {
            \App\Models\Notification::create([
                'user_id'  => $recipients->first()?->id,
                'type'     => 'system_alert',
                'severity' => $isp->isRenewalDue() ? 'critical' : 'warning',
                'title'    => "ISP Renewal: {$isp->provider}",
                'message'  => "ISP contract for {$isp->provider} (" . ($isp->branch?->name ?: 'N/A') . ") is due for renewal on {$isp->renewal_date->format('M d, Y')}.",
                'link'     => '/admin/network/isp',
            ]);
        } catch (\Throwable) {}
    }
})->name('check-isp-renewals')->withoutOverlapping(60)->dailyAt('08:00');

// ─── Printer SNMP Polling — every 5 minutes ─────────────────
Schedule::call(function () {
    try {
        (new \App\Jobs\PollPrinterSnmpJob())->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error("Printer SNMP polling failed: " . $e->getMessage());
    }
})->name('poll-printer-snmp')->withoutOverlapping(5)->everyFiveMinutes();

// ─── Low Toner Monitor — every 30 minutes ────────────────────
Schedule::call(function () {
    try {
        app(\App\Services\PrinterSupplyMonitorService::class)->checkAll();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error("Low toner monitor failed: " . $e->getMessage());
    }
})->name('check-low-toner')->withoutOverlapping(10)->everyThirtyMinutes();

// ─── Metrics Rollup (hourly → daily) + Tiered Pruning ───────
// Rolls raw sensor_metrics into hourly/daily rollup tables.
// Also prunes: raw data >7 days, hourly data >90 days.
// Runs inline (not queued) to avoid queue-worker dependency on shared hosting.
Schedule::call(function () {
    try {
        (new \App\Jobs\RollupMetricsJob())->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error("Metrics rollup failed: " . $e->getMessage());
    }
})->name('rollup-metrics')->withoutOverlapping(30)->hourly();

// ─── Prune Old Sensor Metrics — weekly safety net at 02:00 AM ──
// Kept as a fallback in case RollupMetricsJob is missed. Uses the
// configurable metrics_retention_days setting from the DB.
Schedule::call(function () {
    try {
        (new \App\Jobs\PruneOldMetricsJob())->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error("Prune metrics failed: " . $e->getMessage());
    }
})->name('prune-old-metrics')->withoutOverlapping(60)->weeklyOn(0, '02:00');

// ─── Switch Drop Counter Poll — every 5 minutes ───────────────
Schedule::command('switch:poll-drops')->everyFiveMinutes()->withoutOverlapping(10);

// ─── Cisco MLS QoS Queue Stats Poll — every 5 minutes ─────────
Schedule::command('switch:poll-mls-qos')->everyFiveMinutes()->withoutOverlapping(10);

// ─── Prune VQ, Switch Drop, Workflow data per retention settings ──
Schedule::command('data:prune')
    ->dailyAt('03:00')
    ->withoutOverlapping(30)
    ->name('prune-data');

// ─── Azure / Intune Device Sync — every 6 hours ──────────────────────────
// Inline (no queue) sync of managed devices → populates intune_managed_device_id
// which is required for intune:sync-net-data to match script results.
Schedule::command('itam:sync-devices')
    ->everySixHours()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->name('itam-sync-devices');

// ─── Intune Net Data Sync — daily at 03:30 ───────────────────────────────
// Reads NOC-DeviceInfo.ps1 run results from Graph beta, updates azure_devices
// with TeamViewer ID / CPU / MAC addresses, and populates device_macs for RADIUS.
Schedule::command('intune:sync-net-data')
    ->dailyAt('03:30')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('intune-net-data');

// ─── Browser Portal — Idle Session Cleanup (every 5 minutes) ────────────
// Stops Neko containers whose last_active_at is older than
// BROWSER_PORTAL_IDLE_MINUTES (default 240). Volumes are preserved.
Schedule::call(function () {
    try {
        (new \App\Jobs\BrowserPortal\CleanupIdleSessionsJob)->handle(
            app(\App\Services\BrowserPortal\SessionManager::class)
        );
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Browser portal cleanup failed: ' . $e->getMessage());
    }
})->name('cleanup-browser-sessions')->withoutOverlapping(5)->everyFiveMinutes();

// ─── SSL Certificate Auto-Renewal — daily at 02:00 ───────────────────────
// Renews all ssl_certificates where status='valid', auto_renew=true,
// and expires_at <= now()+14 days. Runs inline (not via queue) so a
// queue worker is not required; each renewal may take up to ~60 s.
Schedule::call(function () {
    try {
        (new \App\Jobs\RenewExpiringCertificatesJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('SSL auto-renewal failed: ' . $e->getMessage());
    }
})->name('renew-expiring-certs')->withoutOverlapping(30)->dailyAt('02:00');

// ─── Onboarding Manager-Form Reminders — daily at 09:00 ──────────────────
// For every workflow still in 'awaiting_manager_form', re-send the setup
// form email (up to 3 reminders, once per 24h) until the manager fills it.
Schedule::command('onboarding:remind-managers')
    ->dailyAt('09:00')
    ->withoutOverlapping(30)
    ->name('remind-onboarding-managers');
