<?php

namespace App\Console\Commands;

use App\Models\BranchLogCollector;
use App\Models\Device;
use App\Models\MonitoredHost;
use App\Models\SnmpDevice;
use App\Models\VpnTunnel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk-imports branch network switches into:
 *   - devices (ITAM asset, type=switch, auto asset_code → Network ▸ Switches)
 *   - monitored_hosts (NOC-polled SNMP, type=switch)
 *   - snmp_devices (branch-agent-polled)
 *
 * SNMP defaults: v2c, community "NOC". Idempotent (keyed by branch + IP).
 * Dry-run by default; pass --apply to write.
 */
class ImportBranchSwitches extends Command
{
    protected $signature = 'switches:import {--apply : Actually write (otherwise dry-run)} {--community=NOC : SNMP community}';

    protected $description = 'Import branch switches into assets, MonitoredHost and SnmpDevice (SNMP v2c)';

    /** [branch code, name, ip] */
    private array $rows = [
        // ── CAI ──
        ['CAI', 'Switch 1 "Access"', '10.9.99.197'],
        ['CAI', 'Switch 4 "Gateways"', '10.9.99.198'],
        ['CAI', 'Switch 2 "Access/APs/BIO"', '10.9.99.199'],
        ['CAI', 'Switch 3 "Core"', '10.9.99.200'],
        // ── ABH ──
        ['ABH', 'Switch 1 - Cisco Catalyst C1300', '10.4.0.3'],
        ['ABH', 'Switch 2 - Cisco Catalyst C1300', '10.4.0.4'],
        // ── KBR ──
        ['KBR', 'Core SW', '10.3.0.11'],
        ['KBR', 'KHB-SW-LAN2', '10.3.0.12'],
        ['KBR', 'KBR-SW_2nd_Floor', '10.3.0.13'],
        ['KBR', 'KBR_VOIP_CORE', '10.3.1.116'],
        ['KBR', 'Ground Floor', '10.3.0.14'],
        ['KBR', '3rdFloor-SW', '10.3.0.25'],
        // ── JED ──
        ['JED', 'Core 1', '10.1.0.100'],
        ['JED', 'Core 2', '10.1.0.101'],
        ['JED', 'Mezanine floor', '10.1.0.103'],
        ['JED', '6th floor', '10.1.0.111'],
        ['JED', '1st floor', '10.1.0.115'],
        ['JED', '2nd floor', '10.1.0.106'],
        // ── RYD ──
        ['RYD', '1st floor', '10.2.0.13'],
        ['RYD', 'VOIP switch', '10.2.0.30'],
        ['RYD', 'core', '10.2.0.5'],
    ];

    private array $branchNameHints = [
        'CAI' => ['cai', 'cairo'],
        'ABH' => ['abh', 'abha'],
        'KBR' => ['kbr', 'khobar', 'khubar'],
        'JED' => ['jed', 'jeddah', 'jiddah'],
        'RYD' => ['ryd', 'riyadh', 'riyad'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $community = (string) $this->option('community') ?: 'NOC';

        $branches = $this->resolveBranches();
        $this->line('');
        $this->info('Branch resolution:');
        $this->table(
            ['Code', 'branches.id', 'Branch name', 'collector_id', 'vpn_tunnel_id'],
            collect($branches)->map(fn ($b, $code) => [
                $code,
                $b['branch_id'] ?? '— UNRESOLVED —',
                $b['branch_name'] ?? '',
                $b['collector_id'] ?? '— none —',
                $b['vpn_id'] ?? '—',
            ])->values()->all(),
        );

        $unresolved = collect($branches)->filter(fn ($b) => empty($b['branch_id']))->keys();
        if ($unresolved->isNotEmpty()) {
            $this->error('Cannot resolve branch_id for: '.$unresolved->implode(', '));
            foreach (\App\Models\Branch::orderBy('id')->get(['id', 'name']) as $br) {
                $this->line("  {$br->id} — {$br->name}");
            }

            return self::FAILURE;
        }

        $this->line('');
        $this->info(sprintf('%d switches to import (SNMP v2c, community "%s"). Mode: %s',
            count($this->rows), $community, $apply ? 'APPLY' : 'DRY-RUN'));

        if (! $apply) {
            $this->table(
                ['Branch', 'Name', 'IP', 'SNMP type'],
                collect($this->rows)->map(fn ($r) => [$r[0], $r[1], $r[2], $this->snmpType($r[1])])->all(),
            );
            $this->warn('Dry-run only — nothing written. Re-run with --apply to create.');

            return self::SUCCESS;
        }

        $stats = ['device' => 0, 'host' => 0, 'snmp_device' => 0];
        DB::transaction(function () use ($branches, $community, &$stats) {
            foreach ($this->rows as [$code, $name, $ip]) {
                $this->upsertOne($branches[$code], $name, trim($ip), $community, $stats);
            }
        });

        $this->info(sprintf('Done. Devices: %d, MonitoredHosts: %d, SnmpDevices: %d.',
            $stats['device'], $stats['host'], $stats['snmp_device']));

        return self::SUCCESS;
    }

    private function upsertOne(array $b, string $name, string $ip, string $community, array &$stats): void
    {
        $branchId = $b['branch_id'];
        [$manufacturer, $model] = $this->makeModel($name);

        // ── Device (asset) ───────────────────────────────────────────
        $device = Device::where('ip_address', $ip)->where('type', 'switch')->first();
        if (! $device) {
            $device = Device::firstOrCreate(
                ['source' => 'manual', 'source_id' => 'switch-import-'.$ip],
                [
                    'type' => 'switch',
                    'name' => $name,
                    'manufacturer' => $manufacturer,
                    'model' => $model,
                    'ip_address' => $ip,
                    'branch_id' => $branchId,
                    'status' => 'active',
                    'asset_code' => $this->assetCode(),
                ],
            );
            $stats['device']++;
        } else {
            $device->fill([
                'name' => $name,
                'manufacturer' => $manufacturer ?: $device->manufacturer,
                'model' => $model ?: $device->model,
                'branch_id' => $branchId,
            ]);
            if (empty($device->asset_code)) {
                $device->asset_code = $this->assetCode();
            }
            $device->save();
            $stats['device']++;
        }

        // ── MonitoredHost (NOC-polled SNMP) ──────────────────────────
        $host = MonitoredHost::firstOrNew(['ip' => $ip, 'branch_id' => $branchId]);
        $host->fill([
            'name' => $name,
            'type' => 'switch',
            'device_id' => $device->id,
            'vpn_id' => $b['vpn_id'],
            'snmp_enabled' => true,
            'snmp_version' => 'v2c',
            'snmp_community' => $community,
            'snmp_port' => 161,
            'ping_enabled' => true,
            'alert_enabled' => false,
        ]);
        if (! $host->exists) {
            $host->status = 'unknown';
        }
        $host->save();
        $stats['host']++;

        // ── SnmpDevice (branch-agent-polled) ─────────────────────────
        if ($b['collector_id']) {
            $sd = SnmpDevice::firstOrNew([
                'branch_log_collector_id' => $b['collector_id'],
                'host' => $ip,
            ]);
            $sd->fill([
                'name' => Str::limit($name, 100, ''),
                'snmp_version' => '2c',
                'snmp_community' => $community,
                'snmp_port' => 161,
                'device_type' => $this->snmpType($name),
                'polling_interval_s' => 60,
                'enabled' => true,
            ])->save();
            $stats['snmp_device']++;
        }
    }

    /** All branch switches are Cisco. */
    private function snmpType(string $name): string
    {
        return 'cisco_switch';
    }

    private function makeModel(string $name): array
    {
        // All Cisco; pull a Catalyst model number out of the name when present.
        if (preg_match('/catalyst\s*([a-z0-9\-]+)/i', $name, $m)) {
            return ['Cisco', 'Catalyst '.strtoupper($m[1])];
        }

        return ['Cisco', null];
    }

    private function assetCode(): string
    {
        if (class_exists(\App\Services\AssetCodeService::class)) {
            try {
                return app(\App\Services\AssetCodeService::class)->generate('switch');
            } catch (\Throwable) {
            }
        }

        return 'SG-SW-'.strtoupper(Str::random(6));
    }

    private function resolveBranches(): array
    {
        $all = \App\Models\Branch::all();
        $out = [];
        foreach ($this->branchNameHints as $code => $hints) {
            $branch = $all->first(function ($br) use ($hints) {
                $n = strtolower((string) $br->name);
                foreach ($hints as $h) {
                    if (str_contains($n, $h)) {
                        return true;
                    }
                }

                return false;
            });
            $out[$code] = [
                'branch_id' => $branch?->id,
                'branch_name' => $branch?->name,
                'collector_id' => BranchLogCollector::where('code', strtolower($code))->value('id'),
                'vpn_id' => $branch ? VpnTunnel::where('branch_id', $branch->id)->value('id') : null,
            ];
        }

        return $out;
    }
}
