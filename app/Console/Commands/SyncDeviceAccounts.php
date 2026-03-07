<?php

namespace App\Console\Commands;

use App\Models\PhoneAccount;
use App\Models\PhoneRequestLog;
use App\Services\GdmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncDeviceAccounts extends Command
{
    protected $signature   = 'gdms:sync-device-accounts
                                {--mac=      : Sync a single MAC address only (any format)}
                                {--unsynced  : Only sync devices that have no account data yet}';

    protected $description = 'Fetch SIP accounts for known phone MACs from GDMS and store in phone_accounts table';

    public function handle(GdmsService $gdms): int
    {
        $onlyMac     = $this->option('mac');
        $onlyUnsynced = $this->option('unsynced');

        if ($onlyMac) {
            // Normalize to the stored format: lowercase, no separators
            $normalizedMac = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $onlyMac));
            $macs = collect([$normalizedMac]);
        } elseif ($onlyUnsynced) {
            // Devices in phone_request_logs that have NO rows in phone_accounts yet
            $syncedMacs = PhoneAccount::distinct()->pluck('mac');
            $macs = PhoneRequestLog::whereNotNull('mac')
                ->whereNotIn('mac', $syncedMacs)
                ->distinct()
                ->pluck('mac');

            if ($macs->isEmpty()) {
                $this->info('All devices are already synced. Nothing to do.');
                return self::SUCCESS;
            }
        } else {
            $macs = PhoneRequestLog::whereNotNull('mac')
                ->distinct()
                ->pluck('mac');
        }

        if ($macs->isEmpty()) {
            $this->info('No MAC addresses found.');
            return self::SUCCESS;
        }

        $this->info("Syncing {$macs->count()} device(s) — up to 60 s each...");

        foreach ($macs as $mac) {
            $this->line("  → {$mac}");

            try {
                $accounts = $gdms->getDeviceAccounts($mac);

                if ($accounts === null) {
                    $this->warn("    No response from device (offline or unreachable).");
                    continue;
                }

                foreach ($accounts as $sip) {
                    PhoneAccount::updateOrCreate(
                        [
                            'mac'           => $mac,
                            'account_index' => $sip['account'] ?? 0,
                        ],
                        [
                            'sip_user_id'    => isset($sip['sipUserId']) && $sip['sipUserId'] !== '' ? $sip['sipUserId'] : null,
                            'sip_server'     => $sip['sipServer']     ?? null,
                            'account_status' => $sip['accountStatus'] ?? null,
                            'is_local'       => (bool) ($sip['local'] ?? false),
                            'fetched_at'     => now(),
                        ]
                    );
                }

                $this->info("    ✓ " . count($accounts) . " account(s) saved.");

            } catch (\Throwable $e) {
                $this->error("    Error: " . $e->getMessage());
                Log::error("gdms:sync-device-accounts failed for MAC {$mac}: " . $e->getMessage());
            }
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
