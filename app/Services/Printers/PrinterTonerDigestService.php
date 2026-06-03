<?php

namespace App\Services\Printers;

use App\Mail\PrinterTonerDigestMail;
use App\Models\EmailLog;
use App\Models\PrinterBranchSetting;
use App\Models\PrinterSupply;
use App\Models\User;
use App\Services\SmtpConfigService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Builds and sends the consolidated monthly low-toner report — one email listing
 * every printer that is currently low on toner, instead of one email per
 * cartridge as the level crosses the threshold.
 */
class PrinterTonerDigestService
{
    /**
     * All low toner cartridges, grouped by branch.
     *
     * @return array<int, array{branch:string, rows:array<int, array{
     *   printer:string, location:string, color:string, percent:int, threshold:int
     * }>}>
     */
    public function collectLowToner(): array
    {
        $supplies = PrinterSupply::with(['printer.branch'])
            ->where('supply_type', 'toner')
            ->whereNotNull('supply_percent')
            ->where('supply_percent', '>=', 0)
            ->whereRaw('supply_percent <= COALESCE(warning_threshold, 20)')
            ->get();

        $groups = [];
        foreach ($supplies as $supply) {
            $printer = $supply->printer;
            if (! $printer) {
                continue;
            }

            $branch = $printer->branch?->name ?? 'Unassigned';
            $groups[$branch] ??= ['branch' => $branch, 'rows' => []];

            $groups[$branch]['rows'][] = [
                'printer' => $printer->printer_name,
                'location' => method_exists($printer, 'locationLabel') ? ($printer->locationLabel() ?: '—') : '—',
                'color' => ucfirst($supply->supply_color ?? ($supply->supply_descr ?? 'toner')),
                'percent' => (int) $supply->supply_percent,
                'threshold' => (int) ($supply->warning_threshold ?? 20),
            ];
        }

        // Sort branches alphabetically, and worst-toner first within each branch.
        ksort($groups);
        foreach ($groups as &$g) {
            usort($g['rows'], fn ($a, $b) => $a['percent'] <=> $b['percent']);
        }

        return array_values($groups);
    }

    /**
     * Resolve digest recipients: every branch's active alert recipients + manager,
     * plus configured digest/fallback emails, deduped. Falls back to admins.
     *
     * @return array<string, string> email => name
     */
    public function recipients(): array
    {
        $emails = [];

        $settings = PrinterBranchSetting::with('activeRecipients')
            ->where('alerts_enabled', true)
            ->get();

        foreach ($settings as $setting) {
            foreach ($setting->activeRecipients as $rec) {
                $email = $rec->effectiveEmail();
                if ($email) {
                    $emails[$email] = $rec->effectiveName() ?? $email;
                }
            }
            if ($setting->manager_email) {
                $emails[$setting->manager_email] = $setting->manager_name ?? $setting->manager_email;
            }
        }

        foreach (array_merge(
            (array) config('printer_alerts.digest.emails', []),
            (array) config('printer_alerts.fallback_emails', [])
        ) as $email) {
            if ($email) {
                $emails[$email] = $emails[$email] ?? $email;
            }
        }

        if (empty($emails)) {
            foreach (User::whereIn('role', ['super_admin', 'admin'])->get() as $user) {
                if ($user->email) {
                    $emails[$user->email] = $user->name ?? $user->email;
                }
            }
        }

        return $emails;
    }

    /**
     * Build and send the digest.
     *
     * @param  bool  $force  Send even when nothing is low (e.g. a manual test).
     * @return array{sent:bool, count:int, recipients:array<int,string>, message:string}
     */
    public function send(bool $force = false): array
    {
        $groups = $this->collectLowToner();
        $total = array_sum(array_map(fn ($g) => count($g['rows']), $groups));

        if ($total === 0 && ! $force) {
            return ['sent' => false, 'count' => 0, 'recipients' => [], 'message' => 'No printers are low on toner — digest not sent.'];
        }

        $recipients = $this->recipients();
        if (empty($recipients)) {
            return ['sent' => false, 'count' => $total, 'recipients' => [], 'message' => 'No digest recipients configured (no branch recipients, digest emails, or admins).'];
        }

        try {
            app(SmtpConfigService::class)->loadFromSettings();
        } catch (\Throwable $e) {
            Log::error('PrinterTonerDigestService: SMTP config load failed: '.$e->getMessage());
        }

        $period = now()->format('F Y');
        $subject = "[SG-NOC] Monthly Low-Toner Report — {$period} ({$total} cartridge".($total === 1 ? '' : 's').')';

        $status = 'sent';
        $error = null;
        try {
            Mail::to(array_keys($recipients))->send(new PrinterTonerDigestMail($groups, $total, $period, $subject));
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            Log::error('PrinterTonerDigestService: send failed: '.$error);
        }

        // Mirror into the global email log (one row per recipient).
        foreach (array_keys($recipients) as $addr) {
            try {
                EmailLog::create([
                    'to_email' => $addr,
                    'to_name' => $recipients[$addr] ?? null,
                    'subject' => $subject,
                    'notification_type' => 'printer_toner_digest',
                    'status' => $status,
                    'error_message' => $error,
                    'sent_at' => now(),
                ]);
            } catch (\Throwable) {
                // Don't fail on audit-log errors.
            }
        }

        if ($status === 'failed') {
            return ['sent' => false, 'count' => $total, 'recipients' => array_keys($recipients), 'message' => "Digest send failed: {$error}"];
        }

        return [
            'sent' => true,
            'count' => $total,
            'recipients' => array_keys($recipients),
            'message' => 'Low-toner digest sent to '.count($recipients)." recipient(s) covering {$total} cartridge(s).",
        ];
    }
}
