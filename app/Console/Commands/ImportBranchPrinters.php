<?php

namespace App\Console\Commands;

use App\Models\BranchLogCollector;
use App\Models\Device;
use App\Models\MonitoredHost;
use App\Models\Printer;
use App\Models\SnmpDevice;
use App\Models\VpnTunnel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk-imports the branch printer list into:
 *   - devices (ITAM asset, type=printer) + printers (snmp_enabled)
 *   - monitored_hosts (NOC-polled SNMP, type=printer)
 *   - snmp_devices (branch-agent-polled, device_type=generic_snmp)
 *
 * Idempotent: re-running updates existing rows (keyed by branch + IP) rather
 * than duplicating. Dry-run by default; pass --apply to write.
 *
 *   php artisan printers:import          # preview + branch resolution
 *   php artisan printers:import --apply  # create/update
 */
class ImportBranchPrinters extends Command
{
    protected $signature = 'printers:import {--apply : Actually write (otherwise dry-run)} {--community=public : SNMP community}';

    protected $description = 'Import the branch printer list into assets, SNMP printers, MonitoredHost and SnmpDevice';

    /** [branch code, manufacturer, model, ip, location] */
    private array $rows = [
        // ── JED ──
        ['JED', 'RICOH', 'RICOH Aficio MP C3002', '10.1.0.60', 'Basement'],
        ['JED', 'RICOH', 'RICOH Aficio MP C3001', '10.1.0.205', 'Mezanin'],
        ['JED', 'RICOH', 'RICOH IM C4500', '10.1.0.39', 'Mezanin'],
        ['JED', 'RICOH', 'RICOH IM C4500', '10.1.0.19', '1st Floor'],
        ['JED', 'RICOH', 'RICOH MP 6503', '10.1.0.42', '2nd Floor'],
        ['JED', 'RICOH', 'RICOH IM C4500', '10.1.0.29', '2nd Floor - Faxroom'],
        ['JED', 'RICOH', 'RICOH MP C4504ex', '10.1.0.78', '3rd Floor'],
        ['JED', 'RICOH', 'RICOH Aficio MP C3002', '10.1.0.45', '4th Floor'],
        ['JED', 'RICOH', 'RICOH IM C4510', '10.1.0.48', '6th Floor'],
        ['JED', 'RICOH', 'RICOH Aficio MP C4501', '10.5.0.17', 'Warehouse'],
        ['JED', 'RICOH', 'RICOH Aficio MP C4501', '10.5.0.11', 'Warehouse'],
        // ── RYD ──
        ['RYD', 'RICOH', 'RICOH Aficio MP C4502', '10.2.0.17', 'Printer HR_Dept'],
        ['RYD', 'NRG', 'NRG MP C2800', '10.2.0.18', 'Printer BIS_Dept'],
        ['RYD', 'RICOH', 'RICOH MP C4503', '10.2.0.20', 'Printer PT & MEDICAL_Dept'],
        ['RYD', 'RICOH', 'RICOH Aficio MP C3002', '10.2.0.21', 'Printer finance1_Dept_FAX'],
        ['RYD', 'RICOH', 'RICOH MP C4504', '10.2.0.22', 'Project & Copier Dept'],
        ['RYD', 'RICOH', 'RICOH MP C3003', '10.2.0.24', 'Printer Finance2_Dept'],
        ['RYD', 'RICOH', 'RICOH MP C3003', '10.2.0.25', 'Home Appliances.Dept'],
        ['RYD', 'RICOH', 'RICOH MP C3004ex', '10.2.0.41', 'Credit 2.Dept'],
        ['RYD', 'RICOH', 'RICOH Aficio MP C3001', '10.2.0.33', 'Dental & Pacs'],
        ['RYD', 'RICOH', 'RICOH MP C4504', '10.2.0.34', 'Projects'],
        ['RYD', 'RICOH', 'RICOH Aficio MP C3001', '10.2.0.35', 'CES_TRD_Rasheed'],
        ['RYD', 'RICOH', 'RICOH IM C5510', '10.2.0.38', 'Medical_Zaeem'],
        ['RYD', 'RICOH', 'RICOH MP C3003', '10.2.0.24', 'alarabi + aljarad'], // dup IP with finance2
        ['RYD', 'RICOH', 'RICOH Aficio MP C4502', '10.6.0.5', 'Warehouse'],
        ['RYD', 'RICOH', 'RICOH Aficio MP C3002', '10.8.0.7', 'New building'],
        ['RYD', 'RICOH', 'RICOH Aficio MP C4502', '10.8.0.8', 'New building'],
        // ── KBR ──
        ['KBR', 'RICOH', 'RICOH MP C3003', '10.3.0.18', 'PROGECTS DEP.'],
        ['KBR', 'EPSON', 'EPSON WF-C20590', '10.3.0.15', 'Administration'],
        ['KBR', 'RICOH', 'RICOH MP 5000', '10.3.0.19', 'Administration Fax'],
        ['KBR', 'RICOH', 'RICOH IM C4500', '10.3.0.6', 'CES TRADITIONAL'],
        ['KBR', 'RICOH', 'RICOH MP C4502', '10.7.0.5', 'WAREHOUSE'],
        ['KBR', 'RICOH', 'RICOH MP C4500', '10.3.0.61', 'Administration'],
        // ── ABH ──
        ['ABH', 'EPSON', 'EPSON WF-C20590', '10.4.0.5', 'Ground Floor'],
        ['ABH', 'RICOH', 'RICOH MP C3003', '10.4.0.10', 'First Floor'],
        // ── CAI ──
        ['CAI', 'RICOH', 'RICOH IM C4510', '10.9.80.210', 'Finance — 4th Floor'],
        ['CAI', 'Canon', 'Canon iR1643i', '10.9.1.202', 'HR — 5th Floor'],
        ['CAI', 'Canon', 'Canon MF742C/744C', '10.9.1.201', 'GM — 4th Floor'],
    ];

    /** Branch code → candidate branches.name substrings (lowercase). */
    private array $branchNameHints = [
        'JED' => ['jed', 'jeddah', 'jiddah'],
        'RYD' => ['ryd', 'riyadh', 'riyad'],
        'KBR' => ['kbr', 'khobar', 'khubar'],
        'ABH' => ['abh', 'abha'],
        'CAI' => ['cai', 'cairo'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $community = (string) $this->option('community') ?: 'public';

        // 1) Resolve each branch code → branch_id / collector_id / vpn_id.
        $branches = $this->resolveBranches();
        $this->line('');
        $this->info('Branch resolution:');
        $this->table(
            ['Code', 'branches.id', 'Branch name', 'collector_id (code)', 'vpn_tunnel_id'],
            collect($branches)->map(fn ($b, $code) => [
                $code,
                $b['branch_id'] ?? '— UNRESOLVED —',
                $b['branch_name'] ?? '',
                $b['collector_id'] ? $b['collector_id'].' ('.strtolower($code).')' : '— none —',
                $b['vpn_id'] ?? '—',
            ])->values()->all(),
        );

        $unresolved = collect($branches)->filter(fn ($b) => empty($b['branch_id']))->keys();
        if ($unresolved->isNotEmpty()) {
            $this->error('Cannot resolve branch_id for: '.$unresolved->implode(', '));
            $this->warn('Available branches (id — name):');
            foreach (\App\Models\Branch::orderBy('id')->get(['id', 'name']) as $br) {
                $this->line("  {$br->id} — {$br->name}");
            }
            $this->warn('Adjust $branchNameHints in the command to match, then re-run.');

            return self::FAILURE;
        }

        // 2) Detect duplicate (branch, ip).
        $seen = [];
        $plan = [];
        foreach ($this->rows as [$code, $mfr, $model, $ip, $loc]) {
            $ip = trim($ip);
            $key = $code.'|'.$ip;
            if (isset($seen[$key])) {
                $this->warn("DUPLICATE IP skipped: {$code} {$ip} \"{$loc}\" (already used by \"{$seen[$key]}\") — fix the source data.");

                continue;
            }
            $seen[$key] = $loc;
            $plan[] = compact('code', 'mfr', 'model', 'ip', 'loc');
        }

        $this->line('');
        $this->info(sprintf('%d printers to import across %d branches. Mode: %s',
            count($plan), count($branches), $apply ? 'APPLY' : 'DRY-RUN'));

        if (! $apply) {
            $this->table(
                ['Branch', 'Model', 'IP', 'Location', 'name'],
                collect($plan)->map(fn ($p) => [
                    $p['code'], $p['model'], $p['ip'], $p['loc'], $this->deviceName($p['model'], $p['loc']),
                ])->all(),
            );
            $this->warn('Dry-run only — nothing written. Re-run with --apply to create.');

            return self::SUCCESS;
        }

        // 3) Apply.
        $stats = ['device' => 0, 'printer' => 0, 'host' => 0, 'snmp_device' => 0];
        DB::transaction(function () use ($plan, $branches, $community, &$stats) {
            foreach ($plan as $p) {
                $b = $branches[$p['code']];
                $this->upsertOne($p, $b, $community, $stats);
            }
        });

        $this->info(sprintf(
            'Done. Devices: %d, Printers: %d, MonitoredHosts: %d, SnmpDevices: %d.',
            $stats['device'], $stats['printer'], $stats['host'], $stats['snmp_device'],
        ));

        return self::SUCCESS;
    }

    private function upsertOne(array $p, array $b, string $community, array &$stats): void
    {
        $name = $this->deviceName($p['model'], $p['loc']);
        $branchId = $b['branch_id'];

        // ── Device (asset) + Printer ─────────────────────────────────
        $printer = Printer::where('ip_address', $p['ip'])->where('branch_id', $branchId)->first();
        if (! $printer) {
            $device = Device::firstOrCreate(
                ['source' => 'printer', 'source_id' => 'printer-import-'.$p['ip']],
                [
                    'type' => 'printer',
                    'name' => $name,
                    'manufacturer' => $p['mfr'],
                    'model' => $p['model'],
                    'ip_address' => $p['ip'],
                    'branch_id' => $branchId,
                    'location_description' => $p['loc'],
                    'status' => 'active',
                    'asset_code' => $this->assetCode(),
                ],
            );
            $stats['device']++;

            $printer = new Printer(['device_id' => $device->id]);
        }
        $printer->fill([
            'printer_name' => $name,
            'manufacturer' => $p['mfr'],
            'model' => $p['model'],
            'ip_address' => $p['ip'],
            'branch_id' => $branchId,
            'floor' => $p['loc'],
            'snmp_enabled' => true,
            'snmp_community' => $community,
            'snmp_version' => 2,
        ])->save();
        $stats['printer']++;

        // ── MonitoredHost (NOC-polled) ───────────────────────────────
        $host = MonitoredHost::firstOrNew(['ip' => $p['ip'], 'branch_id' => $branchId]);
        $host->fill([
            'name' => $name,
            'type' => 'printer',
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
                'host' => $p['ip'],
            ]);
            $sd->fill([
                'name' => Str::limit($name, 100, ''),
                'snmp_version' => '2c',
                'snmp_community' => $community,
                'snmp_port' => 161,
                'device_type' => 'generic_snmp',
                'polling_interval_s' => 60,
                'enabled' => true,
            ])->save();
            $stats['snmp_device']++;
        }
    }

    private function resolveBranches(): array
    {
        $out = [];
        foreach ($this->branchNameHints as $code => $hints) {
            $branch = \App\Models\Branch::all()->first(function ($br) use ($hints) {
                $n = strtolower((string) $br->name);
                foreach ($hints as $h) {
                    if (str_contains($n, $h)) {
                        return true;
                    }
                }

                return false;
            });

            $collector = BranchLogCollector::where('code', strtolower($code))->first();
            $vpn = $branch ? VpnTunnel::where('branch_id', $branch->id)->first() : null;

            $out[$code] = [
                'branch_id' => $branch?->id,
                'branch_name' => $branch?->name,
                'collector_id' => $collector?->id,
                'vpn_id' => $vpn?->id,
            ];
        }

        return $out;
    }

    private function deviceName(string $model, string $loc): string
    {
        return trim($model).' — '.trim($loc);
    }

    private function assetCode(): string
    {
        // Reuse the app's generator if present; otherwise a safe fallback.
        if (class_exists(\App\Services\AssetCodeService::class)) {
            try {
                return app(\App\Services\AssetCodeService::class)->generate('printer');
            } catch (\Throwable) {
                // fall through
            }
        }

        return 'SG-PRN-'.strtoupper(Str::random(6));
    }
}
