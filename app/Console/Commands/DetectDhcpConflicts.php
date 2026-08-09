<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\DhcpLease;
use App\Services\DhcpLeaseService;
use Illuminate\Console\Command;

/**
 * On-demand IP conflict detection.
 *
 * The scheduler runs this every 10 minutes via DetectDhcpConflictsJob; this
 * command exists so the window can be tried out and the result inspected
 * without waiting for the next tick.
 */
class DetectDhcpConflicts extends Command
{
    protected $signature = 'dhcp:detect-conflicts
                            {--window= : Minutes a lease counts as present (default '.DhcpLeaseService::CONFLICT_WINDOW_MINUTES.')}
                            {--branch= : Limit to one branch id}';

    protected $description = 'Flag DHCP leases whose address is held by two MACs at once';

    public function handle(DhcpLeaseService $service): int
    {
        $window = $this->option('window') !== null ? (int) $this->option('window') : null;
        $branch = $this->option('branch') !== null ? (int) $this->option('branch') : null;

        $count = $service->detectConflicts($branch, $window);

        $this->info(sprintf(
            'Conflicting leases: %d (window %d min%s)',
            $count,
            $window ?? DhcpLeaseService::CONFLICT_WINDOW_MINUTES,
            $branch ? ", branch {$branch}" : ''
        ));

        if ($count === 0) {
            return self::SUCCESS;
        }

        $branches = Branch::pluck('name', 'id');

        $rows = DhcpLease::where('is_conflict', true)
            ->orderBy('branch_id')
            ->orderBy('ip_address')
            ->get()
            ->groupBy(fn ($l) => ($l->branch_id ?? '-').':'.$l->ip_address)
            ->map(fn ($group, $key) => [
                $branches[$group->first()->branch_id] ?? '—',
                $group->first()->ip_address,
                $group->pluck('mac_address')->implode(', '),
                $group->pluck('source')->unique()->implode(', '),
            ])
            ->values()
            ->all();

        $this->table(['Branch', 'IP Address', 'MACs', 'Sources'], $rows);

        return self::SUCCESS;
    }
}
