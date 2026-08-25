<?php

namespace App\Services\Voice;

use App\Models\Branch;
use App\Models\IpamSubnet;
use App\Models\VoiceQualityReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Works out which branch a voice quality report belongs to.
 *
 * Both collectors used to do this themselves, differently, and both were broken
 * the same way: they matched an IP against `branches.ip_range`, a column that
 * has never existed in any migration. `! empty($branch->ip_range)` is therefore
 * always false, so IP resolution never matched a single report. The UDP daemon
 * had no fallback at all, which is why voice_quality_reports.branch_id is NULL
 * on essentially every historical row.
 *
 * They also disagreed on semantics — the daemon matched the reporting phone's
 * local IP, the HTTP endpoint matched the far end's remote IP. This class is the
 * one definition, used by both.
 *
 * Named VoiceBranchResolver to avoid colliding with the unrelated
 * App\Services\Ticketing\BranchResolver, which maps IPs to branch names for the
 * ticket portal.
 */
class VoiceBranchResolver
{
    /** @var Collection<int, Branch>|null */
    private ?Collection $branches = null;

    /** @var Collection<int, IpamSubnet>|null */
    private ?Collection $subnets = null;

    /**
     * Resolve to a branch, or null when nothing matches confidently.
     *
     * Order, most to least reliable:
     *   1. an explicit branch id the caller already has
     *   2. a private IP inside one of the branch's IPAM subnets
     *   3. an extension inside the branch's configured range
     *
     * A guess is worse than a null here: an unattributed report is visibly
     * missing, whereas a misattributed one quietly corrupts another branch's
     * call quality score.
     */
    public function resolve(?int $branchId = null, ?string $ip = null, ?string $extension = null): ?Branch
    {
        if ($branchId !== null) {
            $branch = $this->branches()->firstWhere('id', $branchId);
            if ($branch) {
                return $branch;
            }
        }

        return $this->resolveByIp($ip) ?? $this->resolveByExtension($extension);
    }

    /**
     * Match a private address against the branch-linked IPAM subnets.
     *
     * Public addresses are skipped outright: a report's remote IP is often the
     * NATed public address of the far end, which belongs to no branch subnet and
     * would only ever produce a false match.
     *
     * IpamSubnet::containsIp() is the existing CIDR implementation — this is the
     * real per-branch network data that the dead `ip_range` lookup was reaching
     * for all along.
     */
    public function resolveByIp(?string $ip): ?Branch
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null; // public address — not a branch LAN
        }

        // containsIp() is IPv4-only and assumes a well-formed cidr; a subnet row
        // someone typed by hand should not take the collector down.
        foreach ($this->subnets() as $subnet) {
            try {
                if ($subnet->containsIp($ip)) {
                    return $this->branches()->firstWhere('id', $subnet->branch_id);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Match an extension against branches.ext_range_start/ext_range_end.
     *
     * Reads the raw columns rather than Branch::effectiveExtRange(), which falls
     * back to a global default of 1000-1999 whenever a branch leaves them null.
     * Going through the helper would put every unconfigured branch on the same
     * interval and hand all their calls to whichever matched first.
     */
    public function resolveByExtension(?string $extension): ?Branch
    {
        $extension = trim((string) $extension);

        if ($extension === '' || ! ctype_digit($extension)) {
            return null;
        }

        $value = (int) $extension;

        return $this->branches()->first(
            fn (Branch $b) => $b->ext_range_start !== null
                && $b->ext_range_end !== null
                && $value >= (int) $b->ext_range_start
                && $value <= (int) $b->ext_range_end
        );
    }

    /**
     * Legacy prefix map, as a last resort.
     *
     * Returns a branch CODE string ('JED'), not an id — `branches` has no code
     * column, so this can only ever populate the denormalized `branch` text
     * column, exactly as it did before. Kept so existing reports and filters
     * keep working while real attribution takes over.
     */
    public function legacyBranchLabel(?string $extension): ?string
    {
        return VoiceQualityReport::branchFromExtension($extension);
    }

    /**
     * Resolve and shape the two columns a report carries, in one call.
     *
     * @return array{branch_id: int|null, branch: string|null}
     */
    public function attribute(?int $branchId = null, ?string $ip = null, ?string $extension = null): array
    {
        $branch = $this->resolve($branchId, $ip, $extension);

        return [
            'branch_id' => $branch?->id,
            'branch' => $branch?->name ?? $this->legacyBranchLabel($extension),
        ];
    }

    /**
     * Drop the memoized lookups.
     *
     * The UDP collector is a long-running daemon: it used to read Branch::all()
     * once before its accept loop, so a branch added or edited afterwards stayed
     * invisible until someone restarted the service. It calls this periodically
     * instead.
     */
    public function flush(): void
    {
        $this->branches = null;
        $this->subnets = null;
    }

    /** @return Collection<int, Branch> */
    private function branches(): Collection
    {
        return $this->branches ??= Schema::hasTable('branches')
            ? Branch::orderBy('id')->get()
            : collect();
    }

    /** @return Collection<int, IpamSubnet> */
    private function subnets(): Collection
    {
        return $this->subnets ??= Schema::hasTable('ipam_subnets')
            ? IpamSubnet::whereNotNull('branch_id')->get()
            : collect();
    }
}
