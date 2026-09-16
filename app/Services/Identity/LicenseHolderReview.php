<?php

namespace App\Services\Identity;

use App\Models\Employee;
use App\Models\IdentityLicense;
use App\Models\IdentitySyncLog;
use App\Models\IdentityUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Microsoft 365 license review in ITAM ▸ Reports: every account holding a
 * paid Microsoft licence, sorted by LicenseHolderClassifier.
 *
 * Built only from what the NOC already holds, never from Graph, so the page
 * costs a few queries:
 * - identity_users' licences, refreshed every five minutes by the licence sync;
 * - the SKU list, with the price entered on the linked ITAM licence;
 * - the employee list;
 * - each employee's latest fingerprint punch.
 */
final class LicenseHolderReview
{
    /**
     * A SKU is free when Microsoft hands out thousands of units (Power Automate
     * Free, Power BI free) or it is tenant-wide. One in grace ("Warning") still
     * counts as paid; a suspended or deleted one cannot be assigned any more.
     */
    private const FREE_UNITS = 10000;

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array<string, array{count: int, costs: array<string, float>}>, total: array{count: int, costs: array<string, float>}, licenses_synced_at: ?CarbonImmutable, accounts_synced_at: ?CarbonImmutable}
     */
    public static function build(): array
    {
        $skus = [];
        foreach (IdentityLicense::with('itamLicense:id,cost,currency')->get() as $sku) {
            $cost = $sku->itamLicense?->cost;
            $skus[$sku->sku_id] = [
                'name' => $sku->display_name ?: $sku->sku_part_number,
                'paid' => in_array($sku->capability_status ?: 'Enabled', ['Enabled', 'Warning'], true)
                    && (int) $sku->total < self::FREE_UNITS
                    && $sku->applies_to !== 'Company',
                'cost' => $cost !== null && (float) $cost > 0 ? (float) $cost : null,
                'currency' => $sku->itamLicense?->currency,
            ];
        }

        $accounts = IdentityUser::query()->toBase()
            ->get(['azure_id', 'user_principal_name', 'mail', 'display_name', 'account_enabled', 'assigned_licenses'])
            ->map(fn ($u) => [
                'id' => (string) $u->azure_id,
                'userPrincipalName' => (string) $u->user_principal_name,
                'mail' => $u->mail,
                'displayName' => $u->display_name,
                'accountEnabled' => (bool) $u->account_enabled,
                'skuIds' => array_values(array_filter((array) json_decode((string) $u->assigned_licenses, true), 'is_string')),
            ])
            ->all();

        $employees = Employee::query()
            ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
            ->toBase()
            ->get([
                'employees.id', 'employees.name', 'employees.email', 'employees.azure_id', 'employees.status',
                'employees.employee_type', 'employees.oracle_emp_no', 'employees.linked_primary_employee_id',
                'employees.terminated_date', 'branches.name as branch',
            ]);

        $lastPunch = Schema::hasTable('attendance_punches')
            ? DB::table('attendance_punches')
                ->whereNotNull('employee_id')
                ->groupBy('employee_id')
                ->selectRaw('employee_id, max(punch_time) as last_punch')
                ->pluck('last_punch', 'employee_id')
                ->all()
            : [];

        $rows = LicenseHolderClassifier::classify($accounts, $skus, $employees, $lastPunch);

        $summary = array_map(fn () => ['count' => 0, 'costs' => []], LicenseHolderClassifier::CATEGORIES);
        $total = ['count' => 0, 'costs' => []];
        foreach ($rows as $row) {
            $summary[$row['category']]['count']++;
            $total['count']++;
            foreach ($row['costs'] as $currency => $amount) {
                $summary[$row['category']]['costs'][$currency] = ($summary[$row['category']]['costs'][$currency] ?? 0) + $amount;
                $total['costs'][$currency] = ($total['costs'][$currency] ?? 0) + $amount;
            }
        }

        $licensesSyncedAt = Cache::get(LicenseAssignmentSync::LAST_RUN_CACHE_KEY);
        $accountsSyncedAt = IdentitySyncLog::where('status', 'completed')->latest()->value('created_at');

        return [
            'rows' => $rows,
            'summary' => $summary,
            'total' => $total,
            'licenses_synced_at' => $licensesSyncedAt ? CarbonImmutable::parse($licensesSyncedAt) : null,
            'accounts_synced_at' => $accountsSyncedAt ? CarbonImmutable::parse($accountsSyncedAt) : null,
        ];
    }
}
