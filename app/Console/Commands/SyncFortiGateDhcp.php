<?php

namespace App\Console\Commands;

use App\Jobs\SyncFortiGateDhcpJob;
use App\Models\FortigateFirewall;
use App\Services\FortiGate\FortiGateApiService;
use Illuminate\Console\Command;

class SyncFortiGateDhcp extends Command
{
    protected $signature = 'fortigate:sync-dhcp
                            {--firewall= : Sync only this firewall (id, name or IP)}
                            {--test : Only test API connectivity, do not write leases}
                            {--all : Include firewalls with auto-sync disabled}';

    protected $description = 'Pull DHCP leases from FortiGate firewalls into dhcp_leases';

    public function handle(): int
    {
        $query = FortigateFirewall::query();

        if ($target = $this->option('firewall')) {
            $query->where(function ($q) use ($target) {
                $q->where('id', $target)
                    ->orWhere('name', $target)
                    ->orWhere('ip', $target);
            });
        } elseif (! $this->option('all')) {
            $query->where('sync_enabled', true);
        }

        $firewalls = $query->orderBy('name')->get();

        if ($firewalls->isEmpty()) {
            $this->warn('No matching FortiGate firewalls.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($firewalls as $fw) {
            if ($this->option('test')) {
                $result = (new FortiGateApiService($fw))->testConnection();
                if ($result['success']) {
                    $meta = array_filter($result['meta']);
                    $this->info("✓ {$fw->name} ({$fw->ip}) — ".(implode(' · ', $meta) ?: 'connected'));
                } else {
                    $this->error("✗ {$fw->name} ({$fw->ip}) — {$result['message']}");
                    $failed++;
                }

                continue;
            }

            try {
                $count = (new SyncFortiGateDhcpJob($fw))->handle();
                $this->info("✓ {$fw->name} ({$fw->ip}) — {$count} leases");
            } catch (\Throwable $e) {
                $this->error("✗ {$fw->name} ({$fw->ip}) — {$e->getMessage()}");
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
