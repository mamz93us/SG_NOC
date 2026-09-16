<?php

namespace App\Services\Identity;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\IdentityLicense;
use App\Models\IdentityUser;
use App\Models\License;
use App\Models\LicenseAssignment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Makes the NOC's Microsoft licences a copy of Microsoft 365, in one direction:
 * which employee holds which licence (LicenseAssignment rows) and how many
 * seats each licence has (License::seats = Microsoft's enabled units).
 *
 * Nothing here writes to Microsoft. The NOC's own Assign / Unassign /
 * Auto-assign still change Microsoft through Graph; this sync then records
 * whatever Microsoft holds, so a licence changed in the admin centre, through a
 * group, or by those buttons all reach the NOC the same way.
 *
 * Reads Graph live on every run (subscribedSkus plus every user's licences,
 * about three requests) rather than the identity_users mirror, which is only as
 * fresh as the last full identity sync.
 */
class LicenseAssignmentSync
{
    /**
     * Removals above both limits look like a partial user list rather than real
     * licence changes, and are refused. Adds still go through.
     */
    private const MAX_REMOVALS = 25;

    private const MAX_REMOVAL_SHARE = 0.10;

    /** When licences were last copied from Microsoft, for the license review report. */
    public const LAST_RUN_CACHE_KEY = 'identity:license-sync:last-run';

    public function __construct(private GraphService $graph) {}

    /**
     * @param  bool  $apply  false reports the changes without writing anything
     * @param  bool  $refreshSkus  false when the caller has just synced the SKU list itself
     * @return array{users: int, plan: LicenseAssignmentPlan, added: int, removed: int, removals_refused: bool, seats: list<array{license_id: int, name: string, from: int, to: int}>, errors: list<string>}
     */
    public function run(bool $apply = true, bool $refreshSkus = true): array
    {
        if (! $apply) {
            return $this->execute(false, $refreshSkus);
        }

        // The five-minute job and step 5 of the hourly identity:sync can start
        // together; two writers would each add the same missing row.
        return Cache::lock('identity:license-assignment-sync', 300)
            ->block(120, fn () => $this->execute(true, $refreshSkus));
    }

    private function execute(bool $apply, bool $refreshSkus): array
    {
        $errors = [];
        $skus = $this->graph->listSubscribedSkus();

        if ($apply && $refreshSkus) {
            // Creates the ITAM licence for a newly bought SKU, so its assignments have somewhere to go.
            (new IdentitySyncService($this->graph))->syncLicenses($errors, $skus);
        }

        $seats = $this->seatChanges($skus);

        $users = $this->graph->listUsersForLicenses();
        $known = IdentityUser::count();
        if ($users === [] || count($users) < $known * 0.5) {
            throw new RuntimeException('Graph returned '.count($users)." users but the NOC knows {$known}; refusing to sync licences from an incomplete list.");
        }

        $licenseBySku = IdentityLicense::whereNotNull('license_id')->pluck('license_id', 'sku_id')
            ->map(fn ($id) => (int) $id)->all();

        $plan = LicenseAssignmentPlan::build(
            $users,
            $licenseBySku,
            Employee::query()->toBase()->get(['id', 'azure_id', 'email', 'status']),
            LicenseAssignment::query()
                ->where('assignable_type', Employee::class)
                ->whereIn('license_id', array_values($licenseBySku) ?: [0])
                ->toBase()
                ->get(['id', 'license_id', 'assignable_id']),
            now()->toDateString(),
        );

        $refused = count($plan->remove) > self::MAX_REMOVALS
            && count($plan->remove) > $plan->current * self::MAX_REMOVAL_SHARE;
        if ($refused) {
            $errors[] = 'Refused to remove '.count($plan->remove)." of {$plan->current} licence assignments: that looks like an incomplete read from Graph, not real changes.";
        }

        $result = [
            'users' => count($users),
            'plan' => $plan,
            'added' => 0,
            'removed' => 0,
            'removals_refused' => $refused,
            'seats' => $seats,
            'errors' => $errors,
        ];

        if (! $apply) {
            return $result;
        }

        $this->refreshIdentityUsers($users);

        DB::transaction(function () use ($plan, $refused, $seats, &$result) {
            foreach ($seats as $change) {
                License::find($change['license_id'])?->update(['seats' => $change['to']]);
            }

            foreach ($plan->add as $row) {
                LicenseAssignment::create([
                    'license_id' => $row['license_id'],
                    'assignable_type' => Employee::class,
                    'assignable_id' => $row['employee_id'],
                    'assigned_date' => $row['assigned_date'],
                    'notes' => LicenseAssignmentPlan::NOTE,
                ]);
                $result['added']++;
            }

            if (! $refused && $plan->remove) {
                // One by one, so every removal lands in the audit log.
                LicenseAssignment::whereKey($plan->remove)->get()->each(function (LicenseAssignment $row) use (&$result) {
                    $row->delete();
                    $result['removed']++;
                });
            }
        });

        Cache::forever(self::LAST_RUN_CACHE_KEY, now()->toIso8601String());

        if ($result['added'] || $result['removed'] || $seats) {
            ActivityLog::log('Microsoft licence sync', [
                'added' => $result['added'],
                'removed' => $result['removed'],
                'seats' => $seats,
                'unmatched' => count($plan->unmatched),
            ]);
        }

        return $result;
    }

    /**
     * Linked ITAM licences whose seat count differs from Microsoft's enabled units.
     * Suspended and warning units are not counted: they cannot be assigned and
     * are not renewed, so an expired subscription goes to 0.
     *
     * @return list<array{license_id: int, name: string, from: int, to: int}>
     */
    private function seatChanges(array $skus): array
    {
        $enabledBySku = [];
        foreach ($skus as $sku) {
            $enabledBySku[$sku['skuId']] = (int) ($sku['prepaidUnits']['enabled'] ?? 0);
        }

        $changes = [];
        $links = IdentityLicense::whereNotNull('license_id')->with('itamLicense:id,license_name,seats')->get();
        foreach ($links as $link) {
            $license = $link->itamLicense;
            if (! $license || ! isset($enabledBySku[$link->sku_id])) {
                continue;
            }
            $to = $enabledBySku[$link->sku_id];
            if ((int) $license->seats !== $to) {
                $changes[] = ['license_id' => $license->id, 'name' => $license->license_name, 'from' => (int) $license->seats, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * Keeps identity_users' licence columns as fresh as this run, for the
     * identity pages and Auto-assign's "already holds it" check. Written with
     * the query builder like syncUsers() does: it is a mirror, not an event.
     */
    private function refreshIdentityUsers(array $users): void
    {
        $skusById = [];
        foreach ($users as $user) {
            $skus = array_values(array_filter(array_map(fn ($l) => $l['skuId'] ?? null, $user['assignedLicenses'] ?? [])));
            $skusById[$user['id']] = $skus;
        }

        IdentityUser::query()
            ->whereIn('azure_id', array_keys($skusById))
            ->get(['id', 'azure_id', 'assigned_licenses'])
            ->each(function (IdentityUser $mirror) use ($skusById) {
                $now = $skusById[$mirror->azure_id];
                $was = (array) $mirror->assigned_licenses;
                sort($now);
                sort($was);
                if ($now !== $was) {
                    DB::table('identity_users')->where('id', $mirror->id)->update([
                        'assigned_licenses' => json_encode($skusById[$mirror->azure_id]),
                        'licenses_count' => count($now),
                        'updated_at' => now(),
                    ]);
                }
            });
    }
}
