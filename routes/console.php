<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ──────────────────────────────────────────────────────────────────────
// Load intervals from DB (with safe defaults if not yet configured).
// Wrapped to tolerate the case where the table doesn't exist yet
// (fresh install before migrations; in-memory test DBs at bootstrap).
// ──────────────────────────────────────────────────────────────────────
try {
    $settings = \App\Models\Setting::first();
} catch (\Throwable) {
    $settings = null;
}
$gdmsInterval = max(1, (int) ($settings?->gdms_sync_interval ?: 5));
$merakiInterval = max(1, (int) ($settings?->meraki_polling_interval ?: 5));
$identityInterval = max(1, (int) ($settings?->identity_sync_interval ?: 720));

// Helper: build a valid cron expression for "every N minutes".
// Cron's minute field is 0–59, so naive */N breaks for N >= 60. This collapses
// hourly / multi-hour / daily intervals to the correct multi-field form.
$everyN = function (int $n): string {
    if ($n <= 1) {
        return '* * * * *';
    }            // every minute
    if ($n < 60) {
        return "*/{$n} * * * *";
    }       // every N minutes
    if ($n === 60) {
        return '0 * * * *';
    }            // hourly
    if ($n % 60 === 0 && ($n / 60) <= 23) {
        $h = intdiv($n, 60);

        return "0 */{$h} * * *";                                              // every H hours on the hour
    }
    if ($n >= 1440) {
        return '0 0 * * *';
    }            // daily floor
    // Non-whole-hour intervals > 60min: round down to nearest hour.
    $h = max(1, intdiv($n, 60));

    return "0 */{$h} * * *";
};

// ─── Foreground vs background ───────────────────────────────────────
// schedule:run works through the due events one after another, in the order
// they are defined. An event without runInBackground() runs INSIDE that
// process, so a slow one delays every event below it and keeps that minute's
// schedule:run alive — and the next minute starts another one regardless.
// On 2026-09-11 twelve were stacked (~100 MB each): host pings took 2-4 min
// every minute, printer SNMP 15 min every 5, SNMP metrics 8 min every 2.
//
// So anything that touches the network or can take more than a couple of
// seconds runs in the background, with withoutOverlapping(N) where N minutes
// is longer than its worst run — a lock that expires mid-run lets a second
// copy start. Closures cannot run in the background, so slow ones are
// registered with Artisan::command() and scheduled with Schedule::command().
// Only quick, database-only work stays in the foreground. Per-event durations
// are in /var/log/supervisor/switch-poll.out.log.

// ─── Attendance: the frequent jobs, registered FIRST ────────────────
// Background launches follow file order too. Defined at the end, these waited
// for every foreground event above them — syncs fell behind and queued work
// waited.
// Incremental by each source's watermark; --max-seconds keeps a run inside its
// 5-minute slot. No-ops when no source is configured.
Schedule::command('biotime:sync --max-seconds=240')
    ->everyFiveMinutes()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->name('biotime-sync');

// Work the attendance pages queue instead of doing inline (in the request it
// held a PHP-FPM worker for minutes and ended in a 504): "Sync now",
// recalculations after shift / holiday changes, re-matching codes.
Schedule::command('attendance:work')
    ->everyMinute()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->name('attendance-work');

// GDMS Contact Sync
Schedule::command('gdms:sync-contacts')
    ->cron($everyN($gdmsInterval))
    ->withoutOverlapping(15)
    ->runInBackground();

// GDMS Config Template cache refresh (templates change rarely → daily)
Schedule::command('gdms:sync-templates')
    ->dailyAt('05:30')
    ->withoutOverlapping(10)
    ->runInBackground();

// Meraki Network Sync
Schedule::command('meraki:sync')
    ->cron($everyN($merakiInterval))
    ->withoutOverlapping(10)
    ->runInBackground();

// Azure / Entra ID Identity Sync
Schedule::command('identity:sync')
    ->cron($everyN($identityInterval))
    // Expiry (minutes) must exceed the worst-case run; at 30 the mutex expired
    // mid-run and the next tick launched concurrently -> 19 piled-up syncs.
    ->withoutOverlapping(240)
    ->runInBackground();

// Employee licence assignments — hourly, at :35.
//
// This same step already runs as the LAST thing inside identity:sync, but it
// sat at zero assignments for two months while every sync reported "completed":
// the heavy job evidently was not always reaching the end, and LOG_LEVEL=error
// on production meant its Log::info never recorded that it had been skipped.
//
// Running it standalone as well makes licence data independent of whether the
// big sync finishes. It is cheap (a chunked read plus firstOrCreate) and
// idempotent, so the overlap with identity:sync is harmless — whichever runs
// first, the second is a no-op. Offset to :35 to stay clear of the hourly sync.
Schedule::command('identity:sync-license-assignments')
    ->hourlyAt(35)
    ->withoutOverlapping(30)
    ->runInBackground();

// CUPS Print Manager — status refresh
$cupsInterval = max(1, (int) ($settings?->cups_refresh_interval ?: 5));
Schedule::command('cups:refresh-status')
    ->cron($everyN($cupsInterval))
    ->withoutOverlapping(5)
    ->runInBackground();

// Offboarding lifecycle — auto-disable on last day, reminders, escalation,
// final delete. Runs daily at 23:00; safe to run twice (idempotent).
Schedule::command('offboarding:run-scheduler')
    ->dailyAt('23:00')
    ->withoutOverlapping(10)
    ->runInBackground();

// Prune offboarding backup blobs whose download window expired weeks ago
// (only when the parent workflow is fully completed).
Schedule::command('offboarding:prune-expired-backups')
    ->dailyAt('02:30')
    ->withoutOverlapping(10)
    ->runInBackground();

// Other internal jobs
Schedule::job(new \App\Jobs\RunNocAlertsJob)->everyFiveMinutes();
Schedule::job(new \App\Jobs\CheckLicenseMonitorsJob)->hourly();

// Daily expiry scan — software licenses (ITAM) and SSL certificates.
// Raises NocEvents so the existing notification rules pick them up.
Schedule::call(function () {
    try {
        (new \App\Jobs\CheckExpiryAlertsJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Expiry alerts check failed: '.$e->getMessage());
    }
})->name('check-expiry-alerts')->withoutOverlapping(30)->dailyAt('07:00');

// Warranty Expiry Check — weekly (runs inline)
Schedule::call(function () {
    try {
        (new \App\Jobs\CheckWarrantyExpiryJob)->handle();
    } catch (\Throwable $e) {
    }
})->name('check-warranty-expiry')->withoutOverlapping(60)->weekly();

// Branch Tunnel Watchdog — probes each tunnel's gateway firewall AND every
// subnet the tunnel is supposed to carry, then rolls it up to up/degraded/down
// and raises NocEvents on transitions. Gateway-only checks are not enough: a
// branch firewall keeps answering ICMP while a subnet missing from the tunnel's
// traffic selector is completely unreachable (JED, 2026-07-05 → 2026-08-06).
// Probes run in parallel, so a full sweep is seconds.
Schedule::command('tunnel-health:watch')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->name('tunnel-health-watch');

// Trim watchdog check history to the retention window.
Schedule::command('tunnel-health:watch --prune')
    ->dailyAt('03:20')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('tunnel-health-prune');

// Voice Mesh — the synthetic call prober itself is NOT scheduled here. It needs
// pjsua and takes ~14 minutes for a full sweep of 42 branch pairs, so it runs
// under its own systemd timer on this host (deployment/voice-mesh/) and POSTs
// its report back to /api/voice-mesh/report.
//
// This only notices when that stops happening — otherwise a dead timer would
// leave the matrix frozen on its last green reading.
Schedule::command('voice-mesh:check-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->name('voice-mesh-check-stale');

// Offset from the tunnel prune at 03:20.
Schedule::command('voice-mesh:check-stale --prune')
    ->dailyAt('03:25')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('voice-mesh-prune');

// Audit-trail retention. Every model write lands in activity_logs now, so this
// is what keeps the table — which shares its database with the queue, the cache
// and the sessions — from growing without bound. Security events (sign-ins,
// denials, permission changes) are kept on a longer window; see config/audit.php.
// Offset past the other prunes so they don't contend for the same tables.
Schedule::command('activity-log:prune')
    ->dailyAt('03:40')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->name('activity-log-prune');

// Host ping sweep — hosts are pinged one at a time (3 packets each), so a
// sweep takes 2-4 minutes; hosts pinged within their own interval are skipped.
Artisan::command('hosts:ping', function () {
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

            if (! $result['success']) {
                $event = \App\Models\NocEvent::firstOrCreate(
                    ['source_id' => $host->id, 'source_type' => 'host_down', 'status' => 'open'],
                    ['module' => 'ping', 'branch_id' => $host->branch_id, 'title' => "Host Down: {$host->name}", 'message' => "Ping failed for {$host->ip}.", 'severity' => 'critical', 'first_seen' => now(), 'last_seen' => now()]
                );
                // firstOrCreate will not re-create a still-open incident, so an
                // event raised before branch_id existed needs stamping here or it
                // stays unattributed for as long as the host is down.
                if (! $event->wasRecentlyCreated && $event->branch_id === null && $host->branch_id !== null) {
                    $event->update(['branch_id' => $host->branch_id]);
                }
                if ($event->wasRecentlyCreated && $host->alert_email) {
                    \Illuminate\Support\Facades\Notification::route('mail', $host->alert_email)
                        ->notify(new \App\Notifications\HostOfflineNotification($host));
                }
            } else {
                \App\Models\NocEvent::where('source_id', $host->id)->where('source_type', 'host_down')->where('status', 'open')
                    ->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        } catch (\Throwable $e) {
        }
    }
})->purpose('Ping every ping-enabled monitored host that is due');

Schedule::command('hosts:ping')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('check-host-ping');

// SNMP Metrics Collection — not queued, which would flood the queue. Hosts are
// polled one after another, ~40-50 s each, so a sweep takes ~8 min; "every 2
// minutes" in practice means start again as soon as the last sweep ends.
Artisan::command('snmp:collect-metrics', function () {
    $hosts = \App\Models\MonitoredHost::where('snmp_enabled', true)
        ->where('status', '!=', 'down')
        ->get();
    foreach ($hosts as $host) {
        try {
            (new \App\Jobs\CollectSnmpMetricsJob($host))->handle();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("SNMP metrics failed for {$host->ip}: ".$e->getMessage());
        }
    }
})->purpose('Collect SNMP sensor metrics from every SNMP host that is not down');

Schedule::command('snmp:collect-metrics')
    ->everyTwoMinutes()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->name('collect-snmp-metrics');

// ISP SLA Link Checks — every 5 minutes (runs inline)
Schedule::call(function () {
    $sla = app(\App\Services\SlaMonitorService::class);
    $isps = \App\Models\IspConnection::all();
    foreach ($isps as $isp) {
        try {
            $sla->checkLink($isp);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("SLA check failed for ISP #{$isp->id}: ".$e->getMessage());
        }
    }
})->name('check-isp-sla')->withoutOverlapping(5)->everyFiveMinutes();

// SNMP Device Discovery — once per day. Host by host, and every unreachable
// host costs a full SNMP timeout, so a run takes 5-7 minutes.
Artisan::command('snmp:discover-devices', function () {
    $hosts = \App\Models\MonitoredHost::where('snmp_enabled', true)->get();
    foreach ($hosts as $host) {
        try {
            (new \App\Jobs\DiscoverSnmpDeviceJob($host))->handle();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("SNMP discover failed for {$host->ip}: ".$e->getMessage());
        }
    }
})->purpose('Discover SNMP device details for every SNMP host');

Schedule::command('snmp:discover-devices')
    ->daily()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('discover-snmp-devices');

// SNMP Interface Discovery — once per day, 8-12 minutes for the same reason.
Artisan::command('snmp:discover-interfaces', function () {
    $hosts = \App\Models\MonitoredHost::where('snmp_enabled', true)->get();
    foreach ($hosts as $host) {
        try {
            (new \App\Jobs\DiscoverSnmpInterfacesJob($host))->handle();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("SNMP interface discover failed for {$host->ip}: ".$e->getMessage());
        }
    }
})->purpose('Discover SNMP interfaces for every SNMP host');

Schedule::command('snmp:discover-interfaces')
    ->daily()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('discover-snmp-interfaces');

// ──────────────────────────────────────────────────────────────────────
// Sophos Firewall Sync — configurable interval. A firewall that does not
// answer on its API port costs a full timeout (up to ~1.5 min a run).
// sophos:sync is the same loop, and SyncSophosDataJob logs its own failures.
// ──────────────────────────────────────────────────────────────────────
$sophosInterval = max(5, (int) ($settings?->sophos_sync_interval ?: 15));
Schedule::command('sophos:sync')
    ->cron($everyN($sophosInterval))
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('sync-sophos-data');

// Sophos Central Sync (cloud — APs, firewall fleet, alerts) — configurable interval.
// The command no-ops when the integration is disabled in Settings. Up to 1.5 min.
$sophosCentralInterval = max(5, (int) ($settings?->sophos_central_sync_interval ?: 15));
Schedule::command('sophos-central:sync')
    ->name('sync-sophos-central')->withoutOverlapping(10)->runInBackground()->cron($everyN($sophosCentralInterval));

// ──────────────────────────────────────────────────────────────────────
// FortiGate DHCP Lease Sync — every 10 minutes, a REST call per firewall.
// fortigate:sync-dhcp is the same loop (sync-enabled firewalls only), and
// SyncFortiGateDhcpJob logs its own failures.
// ──────────────────────────────────────────────────────────────────────
Schedule::command('fortigate:sync-dhcp')
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('sync-fortigate-dhcp');

// ARP Table Collection (Sophos hosts) — every 10 minutes, up to ~2 min a run.
Artisan::command('snmp:collect-arp', function () {
    $hosts = \App\Models\MonitoredHost::where('snmp_enabled', true)
        ->where('discovered_type', 'sophos')
        ->get();
    foreach ($hosts as $host) {
        try {
            (new \App\Jobs\CollectArpTableJob($host))->handle();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("ARP collection failed for {$host->ip}: ".$e->getMessage());
        }
    }
})->purpose('Collect ARP tables from Sophos hosts over SNMP');

Schedule::command('snmp:collect-arp')
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('collect-arp-tables');

// DHCP Conflict Detection — every 10 minutes
Schedule::call(function () {
    try {
        (new \App\Jobs\DetectDhcpConflictsJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('DHCP conflict detection failed: '.$e->getMessage());
    }
})->name('detect-dhcp-conflicts')->withoutOverlapping(10)->everyTenMinutes();

// ──────────────────────────────────────────────────────────────────────
// UCM Extension / Trunk / Active Call Sync + Phone-Port Correlation
// ──────────────────────────────────────────────────────────────────────

// Sub-minute events repeat inside each schedule:run until its minute ends, so
// run inline they also held up the rest of the minute: the extension sync
// takes ~25 s (up to 2.5 min) against a 15-second cadence. Not queued — there
// is no worker.

// UCM Extensions + Trunks — every 15 seconds
Artisan::command('ucm:sync-extensions', function () {
    try {
        (new \App\Jobs\SyncUcmExtensionsJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('UCM extension sync failed: '.$e->getMessage());
    }
})->purpose('Sync UCM extensions and trunks');

Schedule::command('ucm:sync-extensions')
    ->everyFifteenSeconds()
    ->withoutOverlapping(15)
    ->runInBackground()
    ->name('sync-ucm-extensions');

// UCM Active Calls — every 15 seconds, ~9 s (up to ~50 s) a run
Artisan::command('ucm:sync-active-calls', function () {
    try {
        (new \App\Jobs\SyncUcmActiveCallsJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('UCM active calls sync failed: '.$e->getMessage());
    }
})->purpose('Sync active calls from the UCMs');

Schedule::command('ucm:sync-active-calls')
    ->everyFifteenSeconds()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('sync-ucm-active-calls');

// Phone-Port MAC Correlation — every minute, a few seconds (up to 30 s) a run
Artisan::command('phones:sync-port-map', function () {
    try {
        (new \App\Jobs\SyncPhonePortMappingJob)->handle(app(\App\Services\PhonePortDetectionService::class));
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Phone-port mapping failed: '.$e->getMessage());
    }
})->purpose('Correlate phone MACs with switch ports');

Schedule::command('phones:sync-port-map')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->name('sync-phone-port-map');

// ──────────────────────────────────────────────────────────────────────
// ISP Renewal Reminders — daily at 8 AM
// Pulls connections with either a billing_day (new cycle-based model) or a
// legacy single-shot renewal_date. needsRenewalReminder() handles both.
// ──────────────────────────────────────────────────────────────────────
Schedule::call(function () {
    $isps = \App\Models\IspConnection::query()
        ->where(function ($q) {
            $q->whereNotNull('billing_day')
                ->orWhereNotNull('renewal_date');
        })
        ->get();

    foreach ($isps as $isp) {
        if (! $isp->needsRenewalReminder()) {
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
            if ($first) {
                $recipients->push($first);
            }
        }

        $recipients->unique('id')->each(function ($user) use ($isp) {
            try {
                $user->notify(new \App\Notifications\IspRenewalReminderNotification($isp));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("ISP renewal notification failed for ISP #{$isp->id}: ".$e->getMessage());
            }
        });

        // Mark as reminded so we don't spam
        $isp->update(['renewal_reminded_at' => now()]);

        // Also create in-app notification
        try {
            $nextDate = $isp->nextRenewalDate();
            $providerName = $isp->ispProvider?->name ?? $isp->provider;
            \App\Models\Notification::create([
                'user_id' => $recipients->first()?->id,
                'type' => 'system_alert',
                'severity' => ($nextDate && $nextDate->isPast()) ? 'critical' : 'warning',
                'title' => "ISP Renewal: {$providerName}",
                'message' => "ISP contract for {$providerName} (".($isp->branch?->name ?: 'N/A').') is due for renewal on '.($nextDate ? $nextDate->format('M d, Y') : 'N/A').'.',
                'link' => '/admin/network/isp',
            ]);
        } catch (\Throwable) {
        }
    }
})->name('check-isp-renewals')->withoutOverlapping(60)->dailyAt('08:00');

// ─── Printer SNMP Polling — every 5 minutes ─────────────────
// A full poll takes ~15 min (up to ~27), so it runs back to back.
Artisan::command('printers:poll-snmp', function () {
    try {
        (new \App\Jobs\PollPrinterSnmpJob)->handle();
        // Sync anything the direct poll missed from host-monitoring sensors
        // (vendor MIBs the host pipeline reads but PollPrinterSnmpJob can't).
        app(\App\Services\Printers\PrinterDiscoveryService::class)->backfillAllFromHostSensors();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Printer SNMP polling failed: '.$e->getMessage());
    }
})->purpose('Poll every printer over SNMP, then backfill from host sensors');

Schedule::command('printers:poll-snmp')
    ->everyFiveMinutes()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('poll-printer-snmp');

// ─── Force Pull All — every minute, flag-gated ───────────────
// The Printers "Force Pull Now" button sets a cache flag for larger fleets;
// this drains it with a forced poll (bypassing the recent-poll lock) so a
// full refresh never has to run inside the web request. The when() check is a
// cache read inside schedule:run, so the forced poll (as long as a normal
// poll) only gets a process when the flag is set.
Artisan::command('printers:force-poll', function () {
    if (! \Illuminate\Support\Facades\Cache::pull('printers.force_poll_all')) {
        return;
    }
    try {
        (new \App\Jobs\PollPrinterSnmpJob(null, true))->handle();
        app(\App\Services\Printers\PrinterDiscoveryService::class)->backfillAllFromHostSensors();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Forced printer SNMP poll failed: '.$e->getMessage());
    }
})->purpose('Forced SNMP poll of every printer, if "Force Pull Now" was pressed');

Schedule::command('printers:force-poll')
    ->everyMinute()
    ->when(fn () => \Illuminate\Support\Facades\Cache::has('printers.force_poll_all'))
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('force-poll-printers');

// ─── Network Discovery Scan Processor — every minute ─────────
// Runs one pending discovery scan (no queue worker in prod). For scans
// started from the Printers "Discover Printers" button (auto_import_printers),
// every printer found is auto-created + polled. This is the async path that
// keeps large /24 sweeps (up to ~12 min) from hitting a web gateway timeout.
Artisan::command('discovery:process-scans', function () {
    $scan = \App\Models\DiscoveryScan::where('status', 'pending')->orderBy('id')->first();
    if (! $scan) {
        return;
    }
    try {
        app(\App\Services\NetworkDiscoveryService::class)->runScan($scan);
        if ($scan->fresh()->auto_import_printers) {
            app(\App\Services\Printers\PrinterDiscoveryService::class)->importScanResults($scan->fresh());
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error("Discovery scan #{$scan->id} failed: ".$e->getMessage());
        $scan->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => $e->getMessage()]);
    }
})->purpose('Run the oldest pending network discovery scan');

Schedule::command('discovery:process-scans')
    ->everyMinute()
    ->when(fn () => \App\Models\DiscoveryScan::where('status', 'pending')->exists())
    ->withoutOverlapping(30)
    ->runInBackground()
    ->name('process-discovery-scans');

// ─── Printer Sensor Discovery — every 10 minutes ─────────────
// Heals SNMP printers that have no sensors yet (DiscoverSnmpDeviceJob is
// dispatched on create but never drained without a worker). Bounded per run,
// still ~45 s of SNMP walks.
Artisan::command('printers:discover-sensors', function () {
    try {
        app(\App\Services\Printers\PrinterDiscoveryService::class)->discoverPrinterSensors(10, true);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Printer sensor discovery failed: '.$e->getMessage());
    }
})->purpose('Discover sensors for up to 10 SNMP printers that have none');

Schedule::command('printers:discover-sensors')
    ->everyTenMinutes()
    ->withoutOverlapping(15)
    ->runInBackground()
    ->name('discover-printer-sensors');

// ─── Low Toner Monitor — every 30 minutes ────────────────────
Schedule::call(function () {
    try {
        app(\App\Services\PrinterSupplyMonitorService::class)->checkAll();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Low toner monitor failed: '.$e->getMessage());
    }
})->name('check-low-toner')->withoutOverlapping(10)->everyThirtyMinutes();

// ─── Monthly Low-Toner Digest ────────────────────────────────
// One consolidated low-toner email per month instead of an email per cartridge.
// The command no-ops unless printer_alerts.toner_email_mode = 'monthly_digest'.
Schedule::command('printers:toner-digest')
    ->monthlyOn((int) config('printer_alerts.digest.day', 1), config('printer_alerts.digest.time', '08:00'))
    ->withoutOverlapping(60)
    ->name('printer-toner-digest');

// ─── Printer Counter Snapshot — daily at 23:55 ────────────────
// Drives the Usage Report by capturing each printer's page counters at
// end-of-day so period diffs (e.g. "pages this month") can be computed.
Schedule::call(function () {
    try {
        (new \App\Jobs\SnapshotPrinterCountersJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Printer counter snapshot failed: '.$e->getMessage());
    }
})->name('snapshot-printer-counters')->withoutOverlapping(60)->dailyAt('23:55');

// ─── Metrics Rollup (hourly → daily) + Tiered Pruning ───────
// Rolls raw sensor_metrics into hourly/daily rollup tables.
// Also prunes: raw data >7 days, hourly data >90 days.
// Not queued (there is no worker). ~10 min a run, up to ~36.
Artisan::command('metrics:rollup', function () {
    try {
        (new \App\Jobs\RollupMetricsJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Metrics rollup failed: '.$e->getMessage());
    }
})->purpose('Roll raw sensor metrics up into hourly/daily tables and prune');

Schedule::command('metrics:rollup')
    ->hourly()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->name('rollup-metrics');

// ─── Prune Old Sensor Metrics — weekly safety net at 02:00 AM ──
// Kept as a fallback in case RollupMetricsJob is missed. Uses the
// configurable metrics_retention_days setting from the DB.
Schedule::call(function () {
    try {
        (new \App\Jobs\PruneOldMetricsJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Prune metrics failed: '.$e->getMessage());
    }
})->name('prune-old-metrics')->withoutOverlapping(60)->weeklyOn(0, '02:00');

// ─── Switch Drop Counter Poll — every 5 minutes, ~4 min a run ─
Schedule::command('switch:poll-drops')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();

// ─── Cisco MLS QoS Queue Stats Poll — every 5 minutes ─────────
// Telnet to each switch; ~1.5 min a run, up to ~7 when switches time out.
Schedule::command('switch:poll-mls-qos')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();

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

// ─── Ad-hoc Queue Drainer — every minute ─────────────────────────────────
// This NOC runs NO long-lived queue worker (scheduler-as-worker model).
// A handful of admin buttons still dispatch ShouldQueue jobs onto the
// `default` DB queue — Intune HW sync (SyncIntuneHwDataJob, 30-min timeout),
// GDMS device-account sync (SyncGdmsDeviceAccountsJob), SSL issuance
// (IssueSslCertificateJob). Without a drainer those rows sit in `jobs`
// forever ("queued… never runs"). This short-lived worker empties the queue
// each minute and exits; withoutOverlapping(60) safely spans the longest job.
Schedule::command('queue:work --stop-when-empty --max-time=280 --tries=1 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('queue-drainer');

// ─── RADIUS MAC Registry Sync — hourly ───────────────────────────────────
// Pulls MACs from `devices` (phones, switches, APs, printers) into the
// `device_macs` registry so they become RADIUS-eligible. Idempotent —
// only writes when something changed.
Schedule::command('radius:sync-macs')
    ->hourly()
    ->withoutOverlapping(15)
    ->runInBackground()
    ->name('radius-sync-macs');

// ─── Browser Portal — Idle Session Cleanup (every 5 minutes) ────────────
// Stops Neko containers whose last_active_at is older than
// BROWSER_PORTAL_IDLE_MINUTES (default 240). Volumes are preserved.
Schedule::call(function () {
    try {
        (new \App\Jobs\BrowserPortal\CleanupIdleSessionsJob)->handle(
            app(\App\Services\BrowserPortal\SessionManager::class)
        );
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Browser portal cleanup failed: '.$e->getMessage());
    }
})->name('cleanup-browser-sessions')->withoutOverlapping(5)->everyFiveMinutes();

// ─── SSL Certificate Auto-Renewal — daily at 02:00 ───────────────────────
// Renews all ssl_certificates where status='valid', auto_renew=true,
// and expires_at <= now()+14 days. Not queued, so a queue worker is not
// required; each renewal may take up to ~60 s.
Artisan::command('ssl:renew-expiring', function () {
    try {
        (new \App\Jobs\RenewExpiringCertificatesJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('SSL auto-renewal failed: '.$e->getMessage());
    }
})->purpose('Renew auto-renew SSL certificates that expire within 14 days');

Schedule::command('ssl:renew-expiring')
    ->dailyAt('02:00')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('renew-expiring-certs');

// ─── Onboarding Manager-Form Reminders — daily at 09:00 ──────────────────
// For every workflow still in 'awaiting_manager_form', re-send the setup
// form email (up to 3 reminders, once per 24h) until the manager fills it.
Schedule::command('onboarding:remind-managers')
    ->dailyAt('09:00')
    ->withoutOverlapping(30)
    ->name('remind-onboarding-managers');

// ──────────────────────────────────────────────────────────────────────
// Syslog pipeline — rsyslog writes raw rows directly into MySQL via
// ommysql; the jobs below classify senders and turn matching rows into
// NocEvents. They run inline (no queue worker on shared hosting).
// ──────────────────────────────────────────────────────────────────────

// Tag source_type / source_id on freshly-received syslog rows by IP
// against SophosFirewall / UcmServer / Printer / MonitoredHost.
Schedule::call(function () {
    try {
        (new \App\Jobs\TagSyslogSourcesJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('TagSyslogSourcesJob failed: '.$e->getMessage());
    }
})->name('tag-syslog-sources')->withoutOverlapping(5)->everyMinute();

// Run user-defined alert rules over recent syslog rows and surface
// matches as NocEvents (so the existing notification routing fires).
Schedule::call(function () {
    try {
        (new \App\Jobs\MatchSyslogAlertsJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('MatchSyslogAlertsJob failed: '.$e->getMessage());
    }
})->name('match-syslog-alerts')->withoutOverlapping(5)->everyMinute();

// Parse vendor-specific KV payloads (Sophos firewalls today; Cisco/UCM
// can be added later) into the syslog_messages.parsed JSON column.
Schedule::call(function () {
    try {
        (new \App\Jobs\ParseSyslogPayloadsJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('ParseSyslogPayloadsJob failed: '.$e->getMessage());
    }
})->name('parse-syslog-payloads')->withoutOverlapping(5)->everyMinute();

// Daily prune — drops syslog_messages rows older than the retention
// window (Setting::syslog_retention_days, default 30).
Schedule::call(function () {
    try {
        (new \App\Jobs\PruneOldSyslogJob)->handle();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('PruneOldSyslogJob failed: '.$e->getMessage());
    }
})->name('prune-old-syslog')->withoutOverlapping(60)->dailyAt('03:30');

// ──────────────────────────────────────────────────────────────────────
// Email Marketing — pick up scheduled campaigns and spend the SES budget
// every minute. Prune email_events daily.
// ──────────────────────────────────────────────────────────────────────
Schedule::command('email-marketing:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command('email-marketing:prune-events')
    ->dailyAt('03:45')
    ->withoutOverlapping(60)
    ->runInBackground();

// Reconcile dynamic email lists (auto_domain) against the employees table.
// EmployeeObserver handles incremental changes; this hourly pass catches
// drift from external writes (identity sync, raw SQL, etc.).
Schedule::command('email-marketing:sync-dynamic-lists')
    ->hourly()
    ->withoutOverlapping(10)
    ->runInBackground();

// ──────────────────────────────────────────────────────────────────────
// Teamtailor — drain pending bulk CV exports (fetch every applicant résumé,
// zip, upload to Azure Blob). No queue worker in production, so this is the
// async path. withoutOverlapping guards against a long export overrunning the
// next tick.
// ──────────────────────────────────────────────────────────────────────
Schedule::command('teamtailor:process-cv-exports')
    ->everyMinute()
    ->withoutOverlapping(20)
    ->runInBackground();

// ──────────────────────────────────────────────────────────────────────
// SFTP-inbox → Azure Blob device backups. Network devices push their own
// backups into the chrooted SFTP inbox on the NOC (deployment/sftp/); the
// sweep streams each stable file up to Azure Blob and deletes the local copy
// so the inbox can't fill the VM disk. No queue worker in prod, so this is the
// async path. The prune enforces Azure-side retention and no-ops unless
// SFTP_BACKUP_RETENTION_DAYS is set.
// ──────────────────────────────────────────────────────────────────────
Schedule::command('sftp-backups:sweep')
    ->cron($everyN(max(1, (int) config('sftp_backup.sweep_interval', 5))))
    ->withoutOverlapping(20)
    ->runInBackground()
    ->name('sftp-backups-sweep');

Schedule::command('sftp-backups:prune')
    ->dailyAt('02:45')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->name('sftp-backups-prune');

// ──────────────────────────────────────────────────────────────────────
// SMTP relay audit log — tail the Postfix maillog into smtp_relay_messages
// for the /admin/smtp-relay page. No-ops where the maillog isn't readable
// (dev boxes). Daily prune enforces smtp_relay.retention_days.
// ──────────────────────────────────────────────────────────────────────
Schedule::command('smtp-relay:ingest-log')
    ->cron($everyN(max(1, (int) config('smtp_relay.ingest_interval', 1))))
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('smtp-relay-ingest-log');

Schedule::command('smtp-relay:ingest-log --prune')
    ->dailyAt('03:20')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->name('smtp-relay-ingest-prune');

// Phone firmware URL fetches — the UI queues a vendor package URL and the NOC
// pulls it in here, so a 150 MB Grandstream ZIP never has to survive a browser
// upload against nginx/PHP body limits.
Schedule::command('firmware:fetch-remote')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('firmware-fetch-remote');

// nginx serves the firmware images, so the app only learns who downloaded one by
// reading nginx's access log. Same scheduler-as-worker shape as smtp-relay:ingest-log.
Schedule::command('firmware:ingest-log')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->name('firmware-ingest-log');

Schedule::command('firmware:ingest-log --prune')
    ->dailyAt('04:20')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('firmware-prune-downloads');

// Refresh what firmware each phone is actually running, so the firmware status
// board and the ITAM Firmware Tracker stop reporting import-time snapshots.
Schedule::command('phones:sync-firmware-versions')
    ->dailyAt('05:45')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('phones-sync-firmware-versions');

// Download Center URL fetches — admins paste a URL and the NOC pulls the file
// into Azure here (async, so a big artifact can't time out the web request).
Schedule::command('downloads:fetch-remote')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('downloads-fetch-remote');

// ──────────────────────────────────────────────────────────────────────
// NOC database → Azure Blob backups. Daily mysqldump | gzip streamed to
// the azure_db_backups disk and recorded in database_backups (history +
// "Backup Now" live on Admin → Server Status). The prune enforces
// Azure-side retention and no-ops unless DB_BACKUP_RETENTION_DAYS is set.
// ──────────────────────────────────────────────────────────────────────
Schedule::command('db-backups:run')
    ->dailyAt((string) config('db_backup.schedule_time', '01:30'))
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('db-backups-run');

Schedule::command('db-backups:prune')
    ->dailyAt('03:15')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->name('db-backups-prune');

// Scheduler heartbeat — Admin → Server Status reads this cache key to tell
// a live supervisor/cron schedule loop from a stalled one.
Schedule::call(function () {
    try {
        cache()->put('scheduler:last_run', now()->toIso8601String(), 3600);
    } catch (\Throwable) {
        // cache table unavailable — the status page just shows "no heartbeat"
    }
})->everyMinute()->name('scheduler-heartbeat');

// Device backup overdue monitor — opens/resolves a NocEvent per backup account
// whose backup is missing within its expected frequency + grace, every 30 min.
Schedule::command('backups:check-overdue')
    ->cron($everyN(30))
    ->withoutOverlapping(15)
    ->runInBackground()
    ->name('backups-check-overdue');

// Branch-agent heartbeat monitor — flags agents stale/down and opens/resolves
// a NocEvent when a branch VM stops checking in, every 5 minutes.
Schedule::command('branch-agents:check-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('branch-agents-check-stale');

// Access-Gateway allowlist — reflect current branch WAN IPs into agw_allowlist
// so the noc-agw gateway lets each branch through. Every 5 minutes.
Schedule::command('agw:sync-allowlist')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('agw-sync-allowlist');

// Access-point ICMP health — APX-series APs have no SNMP, so ping is the
// only direct signal. Pings monitored APs over the branch VPN tunnels and
// opens/resolves a NocEvent on state change. Every 5 minutes.
Schedule::command('access-points:ping')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('access-points-ping');

// Deploy runs are closed by the Node proxy POSTing back, so a proxy restart or
// a hung command would leave a row `running` forever — and that row blocks the
// "one deploy at a time" guard on its server. Reap the stragglers.
Schedule::command('deploy-runs:reap')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('deploy-runs-reap');

// NOC overview uptime snapshots — hourly up/down capture for APs, VPN tunnels
// and hosts so the overview dashboard can chart uptime over time.
Schedule::command('noc:snapshot-availability')
    ->hourly()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('noc-snapshot-availability');

// ── Employee home portal ──────────────────────────────────────────
// Both feed cards on home.samirgroup.net, the page every company PC opens on.
// They exist so that page never calls an external API on request: the whole
// company loads it within minutes of 9am, and a Graph or KnowBe4 round trip per
// visit would be slow and a good way to get throttled.

// Company calendar from the shared M365 mailbox. Hourly is plenty — these are
// holidays and company events, not meeting invites. No-ops when disabled.
Schedule::command('company-calendar:sync')
    ->hourly()
    ->withoutOverlapping(20)
    ->runInBackground()
    ->name('company-calendar-sync');

// KnowBe4 risk scores. Daily and early: the figures move on training and
// phishing campaigns, neither of which changes hour to hour, and the API is
// rate limited. Deliberately before the morning rush so the card is fresh.
Schedule::command('knowbe4:sync')
    ->dailyAt('05:30')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('knowbe4-sync');

// ─── AI IT Assistant — knowledge indexing and retention ──────────
// PDF text extraction + embedding is too slow for an admin's publish click,
// so new/changed employee-library PDFs are picked up here instead of inline.
Schedule::command('ai:index-documents')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->name('ai-index-documents');

// Conversations (and their messages, via cascade) older than
// ai_settings.retention_days — a chat is not an audit record.
Schedule::command('ai:prune-conversations')
    ->dailyAt('03:15')
    ->withoutOverlapping(10)
    ->name('ai-prune-conversations');

// ─── Attendance (ZKTeco BioTime) ──────────────────────────────────
// biotime:sync and attendance:work are registered at the top of this file.

// Per-day counts against BioTime for the last week: re-reads short days,
// reports punches deleted at the source, retries unmapped codes.
Schedule::command('biotime:reconcile --fix')
    ->dailyAt('02:30')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('biotime-reconcile');

// Absences: nobody punched, so no sync touched the day — this records them
// once each shift is over. Hourly for today and yesterday; nightly for the
// week, so a shift or holiday change reaches recent days too.
Schedule::command('attendance:process --days=2')
    ->hourlyAt(15)
    ->withoutOverlapping(30)
    ->runInBackground()
    ->name('attendance-process-recent');

Schedule::command('attendance:process --days=7')
    ->dailyAt('01:30')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('attendance-process-week');
