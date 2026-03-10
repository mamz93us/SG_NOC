<?php

namespace App\Console\Commands;

use App\Jobs\SyncSophosDataJob;
use App\Models\SophosFirewall;
use Illuminate\Console\Command;

class SyncSophosCommand extends Command
{
    protected $signature = 'sophos:sync {--firewall= : Specific firewall ID to sync}';
    protected $description = 'Sync data from Sophos firewalls (interfaces, VPN tunnels, network objects, rules)';

    public function handle(): int
    {
        $firewallId = $this->option('firewall');

        if ($firewallId) {
            $firewall = SophosFirewall::find($firewallId);
            if (!$firewall) {
                $this->error("Firewall ID {$firewallId} not found.");
                return 1;
            }

            $this->info("Syncing firewall: {$firewall->name} ({$firewall->ip})...");
            (new SyncSophosDataJob($firewall))->handle();
            $this->info('Done.');
            return 0;
        }

        $firewalls = SophosFirewall::where('sync_enabled', true)->get();

        if ($firewalls->isEmpty()) {
            $this->warn('No Sophos firewalls configured with sync enabled.');
            return 0;
        }

        $this->info("Syncing {$firewalls->count()} firewall(s)...");

        foreach ($firewalls as $fw) {
            $this->line("  → {$fw->name} ({$fw->ip})");
            try {
                (new SyncSophosDataJob($fw))->handle();
                $this->info("    ✓ Synced successfully");
            } catch (\Throwable $e) {
                $this->error("    ✗ Failed: {$e->getMessage()}");
            }
        }

        $this->info('All done.');
        return 0;
    }
}
