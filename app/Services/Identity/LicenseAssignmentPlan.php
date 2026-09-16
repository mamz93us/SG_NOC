<?php

namespace App\Services\Identity;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What has to change in the NOC so that its Microsoft licence assignments
 * match Microsoft 365. Pure: Graph users, the SKU → ITAM licence links, the
 * employees and the rows the NOC holds go in; rows to add and remove come out.
 *
 * Microsoft is the only source. A row for a Microsoft-linked licence that
 * Microsoft does not assign is removed, however it was created. The sync this
 * replaces only removed rows noted "Auto-synced from Azure", and only for
 * accounts that still existed. On NOC2 on 2026-09-16 that had left 45 rows
 * Microsoft no longer held: 38 on leavers whose Azure accounts were deleted,
 * 7 created by the NOC's own Assign/Auto-assign.
 *
 * Rows of licences not linked to a Microsoft SKU (Adobe, Kaspersky, AI tools)
 * never reach this class; the caller scopes them out.
 */
final class LicenseAssignmentPlan
{
    public const NOTE = 'Auto-synced from Azure';

    /** @var list<array{license_id: int, employee_id: int, assigned_date: string, upn: string, sku_id: string}> */
    public array $add = [];

    /** @var list<int> ids of license_assignments rows to delete */
    public array $remove = [];

    /** @var list<string> UPNs that hold a linked licence and match no employee */
    public array $unmatched = [];

    /** @var array<string, list<int>> UPN => employee ids it could belong to */
    public array $ambiguous = [];

    /** Rows in scope before the change. */
    public int $current = 0;

    /** (licence, employee) pairs Microsoft assigns. */
    public int $desired = 0;

    /**
     * @param  list<array<string, mixed>>  $users  Graph users: id, userPrincipalName, mail, assignedLicenses, licenseAssignmentStates
     * @param  array<string, int>  $licenseBySku  skuId => licenses.id, linked SKUs only
     * @param  iterable<object|array<string, mixed>>  $employees  id, azure_id, email, status
     * @param  iterable<object|array<string, mixed>>  $rows  id, license_id, assignable_id: employee rows of linked licences
     */
    public static function build(array $users, array $licenseBySku, iterable $employees, iterable $rows, string $today): self
    {
        $plan = new self;
        $matcher = new AzureEmployeeMatcher($employees, array_column($users, 'id'));

        $desired = [];
        foreach ($users as $user) {
            $skuIds = [];
            foreach ($user['assignedLicenses'] ?? [] as $licence) {
                if (isset($licenseBySku[$licence['skuId'] ?? ''])) {
                    $skuIds[] = $licence['skuId'];
                }
            }
            if ($skuIds === []) {
                continue;
            }

            $upn = (string) ($user['userPrincipalName'] ?? $user['id']);
            [$employeeId, $candidates] = $matcher->match($user);
            if ($employeeId === null) {
                if ($candidates === []) {
                    $plan->unmatched[] = $upn;
                } else {
                    $plan->ambiguous[$upn] = $candidates;
                }

                continue;
            }

            $appliedOn = [];
            foreach ($user['licenseAssignmentStates'] ?? [] as $state) {
                if (! empty($state['skuId']) && ! empty($state['lastUpdatedDateTime'])) {
                    $appliedOn[$state['skuId']] = substr($state['lastUpdatedDateTime'], 0, 10);
                }
            }

            foreach ($skuIds as $skuId) {
                $key = $licenseBySku[$skuId].':'.$employeeId;
                $desired[$key] ??= [
                    'license_id' => $licenseBySku[$skuId],
                    'employee_id' => $employeeId,
                    'assigned_date' => $appliedOn[$skuId] ?? $today,
                    'upn' => $upn,
                    'sku_id' => $skuId,
                ];
            }
        }
        $plan->desired = count($desired);

        $held = [];
        foreach ($rows as $row) {
            $row = self::toArray($row);
            $plan->current++;
            $key = (int) $row['license_id'].':'.(int) $row['assignable_id'];
            if (! isset($desired[$key])) {
                $plan->remove[] = (int) $row['id'];
            } elseif (isset($held[$key])) {
                // The same licence recorded twice for one employee: keep the older row.
                $plan->remove[] = max((int) $row['id'], $held[$key]);
                $held[$key] = min((int) $row['id'], $held[$key]);
            } else {
                $held[$key] = (int) $row['id'];
            }
        }

        foreach ($desired as $key => $assignment) {
            if (! isset($held[$key])) {
                $plan->add[] = $assignment;
            }
        }

        sort($plan->remove);

        return $plan;
    }

    /** Rows arrive as arrays, query-builder objects or models. */
    private static function toArray(mixed $row): array
    {
        return $row instanceof Arrayable ? $row->toArray() : (array) $row;
    }
}
