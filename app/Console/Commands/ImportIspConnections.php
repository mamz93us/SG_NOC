<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\IspConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports the Mobily/ITC/Zain ISP service list, including the consolidated
 * billing accounts (one payer account covering several services).
 *
 *   php artisan isp:import          # preview + branch resolution
 *   php artisan isp:import --apply  # create/update (idempotent by account_number)
 */
class ImportIspConnections extends Command
{
    protected $signature = 'isp:import {--apply : Actually write (otherwise dry-run)}';

    protected $description = 'Import the branch ISP/internet connection list with billing-account grouping';

    // Consolidated payer accounts (these pay for several services below).
    private const PAYER_1 = '100018945442360';

    private const PAYER_2 = '1001267220500017';

    /**
     * [provider, package, speedMbps, branchCode, purpose, account_number,
     *  customer_type, payment_type, amount, billing_account_number|null]
     */
    private array $rows = [
        // ── Billed under 100018945442360 ──
        ['Mobily', 'Router 5G', 200, 'WHKBR', 'Secondary Connection', '966831024300299', 'business', 'postpaid', 207, self::PAYER_1],
        ['Mobily', 'Business FiberNet', 200, 'JED', 'SG Open', '1001240124197660', 'business', 'postpaid', 402.5, self::PAYER_1],
        ['Mobily', 'Business FiberNet', 500, 'RYD', 'Primary Connection 1', '1001228330393316', 'business', 'postpaid', 862.5, self::PAYER_1],
        ['Mobily', 'Business FiberNet', 200, 'RYD', 'Secondary Connection 2', '1001235655603630', 'business', 'postpaid', 402.5, self::PAYER_1],
        ['Mobily', 'Business FiberNet', 200, 'RYD', 'SG Open', '1001235225110987', 'business', 'postpaid', 402.5, self::PAYER_1],
        ['Mobily', 'Business FiberNet', 200, 'RYD', 'New Riyadh Office (Internet only)', '1001252441165869', 'business', 'postpaid', 402.5, self::PAYER_1],
        ['Mobily', 'Business FiberNet', 200, 'ABH', 'SG-OPEN', '1001270311705154', 'business', 'postpaid', 402.5, self::PAYER_1],

        // ── Billed under 1001267220500017 ──
        ['Mobily', 'Router 5G', 200, 'KBR', 'Secondary Connection', '1001267220500017', 'business', 'postpaid', 289.8, self::PAYER_2],
        ['Mobily', 'Business FiberNet', 200, 'JED', 'Secondary Connection', '1001267221254544', 'business', 'postpaid', 350, self::PAYER_2],

        // ── Pay separately (own account) ──
        ['ITC (SALAM)', 'Zoom Fiber Internet', 240, 'KBR', 'Primary Connection', '12122207', 'home', 'prepaid', 379.5, null],
        ['Zain', 'Zain Fiber', 200, 'KBR', 'SG-OPEN', '2016067461', 'home', 'postpaid', 401, null],
        ['Mobily', 'Broadband Business', 100, 'JED', 'Primary Connection (Samir food)', '1000147110643228', 'business', 'postpaid', 573.85, null],
    ];

    private array $branchNameHints = [
        'JED' => ['jed', 'jeddah', 'jiddah'],
        'RYD' => ['ryd', 'riyadh', 'riyad'],
        'KBR' => ['kbr', 'khobar', 'khubar'],
        'WHKBR' => ['kbr', 'khobar', 'khubar'], // warehouse rolls up to KBR
        'ABH' => ['abh', 'abha'],
    ];

    public function handle(): int
    {
        $branches = $this->resolveBranches();

        $this->line('');
        $this->info('Branch resolution:');
        $this->table(['Code', 'branches.id', 'Branch name'], collect($branches)->map(
            fn ($b, $code) => [$code, $b['id'] ?? '— UNRESOLVED —', $b['name'] ?? '']
        )->values()->all());

        $unresolved = collect($branches)->filter(fn ($b) => empty($b['id']))->keys();
        if ($unresolved->isNotEmpty()) {
            $this->error('Cannot resolve branch_id for: '.$unresolved->implode(', '));
            $this->warn('Available branches (id — name):');
            foreach (Branch::orderBy('id')->get(['id', 'name']) as $br) {
                $this->line("  {$br->id} — {$br->name}");
            }

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->line('');
        $this->info(sprintf('%d ISP services to import. Mode: %s', count($this->rows), $apply ? 'APPLY' : 'DRY-RUN'));

        if (! $apply) {
            $this->table(
                ['Provider', 'Package', 'Mbps', 'Branch', 'Use', 'Account #', 'Billed under', 'Amount'],
                collect($this->rows)->map(fn ($r) => [
                    $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[9] ?: '(self)', number_format($r[8], 2).' SAR',
                ])->all(),
            );
            $this->renderBillingSummary();
            $this->warn('Dry-run only — nothing written. Re-run with --apply to import.');

            return self::SUCCESS;
        }

        $count = 0;
        DB::transaction(function () use ($branches, &$count) {
            foreach ($this->rows as $r) {
                [$provider, $package, $mbps, $code, $purpose, $account, $custType, $payType, $amount, $payer] = $r;

                IspConnection::updateOrCreate(
                    ['account_number' => $account],
                    [
                        'branch_id' => $branches[$code]['id'],
                        'provider' => $provider,
                        'package' => $package,
                        'billing_account_number' => $payer,
                        'purpose' => $purpose,
                        'connection_type' => $this->connectionType($package),
                        'customer_type' => $custType,
                        'payment_type' => $payType,
                        'speed_down' => $mbps,
                        'speed_up' => $mbps, // symmetric business links (200/200)
                        'monthly_cost' => $amount,
                        'currency' => 'SAR',
                        'notes' => $code === 'WHKBR' ? 'Warehouse (KBR)' : null,
                    ],
                );
                $count++;
            }
        });

        $this->info("Done. Imported/updated {$count} ISP connections.");
        $this->renderBillingSummary();

        return self::SUCCESS;
    }

    private function renderBillingSummary(): void
    {
        $this->line('');
        $this->info('Billing accounts:');
        $groups = collect($this->rows)->groupBy(fn ($r) => $r[9] ?: '(separate)');
        $rows = $groups->map(fn ($g, $payer) => [
            $payer,
            $g->count(),
            number_format($g->sum(fn ($r) => $r[8]), 2).' SAR',
        ])->values()->all();
        $this->table(['Payer account', 'Services', 'Total / month'], $rows);
    }

    private function connectionType(string $package): ?string
    {
        $p = strtolower($package);

        return match (true) {
            str_contains($p, 'router 5g') || str_contains($p, '5g') => '5g',
            str_contains($p, 'fiber') => 'fiber',
            str_contains($p, 'broadband') => 'copper',
            default => null,
        };
    }

    private function resolveBranches(): array
    {
        $all = Branch::all();
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
            $out[$code] = ['id' => $branch?->id, 'name' => $branch?->name];
        }

        return $out;
    }
}
