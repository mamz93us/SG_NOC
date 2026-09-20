<?php

namespace App\Services\Itam;

use App\Models\AssetHistory;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What happened to assets in a period — handed from one employee to another,
 * moved to or from a store, retired, or scrapped — as one list for finance.
 *
 * Finance posts these against Oracle's fixed-asset register, so every row
 * carries the **Oracle asset number** beside the NOC's own code, who it came
 * from and went to, the reason (from AssetReasons, not free text) and who
 * recorded it. The rows are read from `asset_history`, which every one of
 * those actions already writes, so the report can never disagree with the
 * asset's own history.
 *
 * A scrap appears on the day it was **approved** (the `scrapped` event), not
 * when it was asked for: an unapproved request is not a movement yet.
 */
class AssetMovements
{
    public const KINDS = [
        'transfer' => 'Transfer',
        'store' => 'To or from a store',
        'retire' => 'Retired',
        'scrap' => 'Scrapped',
    ];

    private const EVENTS = [
        'transferred' => 'transfer',
        'moved_to_storage' => 'store',
        'retired' => 'retire',
        'scrapped' => 'scrap',
    ];

    /**
     * @param  array{from?: ?string, to?: ?string, kind?: ?string, branch?: ?int, employee?: ?int, oracle?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $filters): Collection
    {
        $events = array_keys(self::EVENTS);

        if (! empty($filters['kind']) && array_key_exists($filters['kind'], self::KINDS)) {
            $events = array_keys(array_filter(self::EVENTS, fn (string $kind) => $kind === $filters['kind']));
        }

        $query = AssetHistory::query()
            ->with(['device.branch', 'user:id,name'])
            ->whereIn('event_type', $events)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // A movement is dated by the day it happened — the handover date, the retirement
        // date — which can be earlier than the day it was recorded. So the window read
        // from the database is padded, and the period is applied to the effective date.
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'])->subYear()->toDateString().' 00:00:00');
        }
        if (! empty($filters['to'])) {
            // Padded the same way: a movement is often recorded days after it happened.
            $query->where('created_at', '<=', CarbonImmutable::parse($filters['to'])->addYear()->toDateString().' 23:59:59');
        }
        if (! empty($filters['branch'])) {
            $branchId = (int) $filters['branch'];
            $query->where(fn (Builder $q) => $q
                ->where('meta->branch_id', $branchId)
                ->orWhereHas('device', fn (Builder $d) => $d->where('branch_id', $branchId)));
        }
        if (! empty($filters['employee'])) {
            $employeeId = (int) $filters['employee'];
            $query->where(fn (Builder $q) => $q
                ->where('meta->from_employee_id', $employeeId)
                ->orWhere('meta->to_employee_id', $employeeId));
        }
        if (! empty($filters['oracle'])) {
            $number = trim((string) $filters['oracle']);
            $query->whereHas('device', fn (Builder $d) => $d->where('oracle_asset_number', $number));
        }

        $events = $query->get();
        $codes = $this->employeeCodes($events);
        $rows = $events->map(fn (AssetHistory $event) => $this->row($event, $codes));

        if (! empty($filters['from'])) {
            $from = CarbonImmutable::parse($filters['from'])->startOfDay();
            $rows = $rows->filter(fn (array $row) => $row['date'] === null || $row['date']->greaterThanOrEqualTo($from));
        }
        if (! empty($filters['to'])) {
            $to = CarbonImmutable::parse($filters['to'])->endOfDay();
            $rows = $rows->filter(fn (array $row) => $row['date'] === null || $row['date']->lessThanOrEqualTo($to));
        }

        return $rows->sortByDesc('date')->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, kinds: array<string, int>, reasons: array<string, int>, cost: array<string, float>}
     */
    public function summary(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'kinds' => $rows->groupBy('kind')->map->count()->all(),
            'reasons' => $rows->whereNotNull('reason_label')->groupBy('reason_label')->map->count()->sortDesc()->all(),
            // Only what leaves the books: finance writes off a scrapped or retired asset's cost.
            'cost' => $rows->whereIn('kind', ['retire', 'scrap'])->whereNotNull('purchase_cost')
                ->groupBy('currency')->map(fn (Collection $group) => (float) $group->sum('purchase_cost'))->all(),
        ];
    }

    /**
     * The Oracle employee number beside each name: finance posts a movement
     * against the person's Oracle record, and two people can share a name.
     *
     * A movement recorded from now on carries the numbers in its own meta.
     * Older ones are resolved here — a transfer by the employee ids it has
     * always stored, a retirement or a scrap by matching the holder's name
     * against that asset's own assignment history, which is where that name
     * was read from in the first place.
     *
     * @param  Collection<int, AssetHistory>  $events
     * @return array{ids: array<int, string>, holders: array<string, string>}
     */
    private function employeeCodes(Collection $events): array
    {
        $ids = [];
        $devices = [];

        foreach ($events as $event) {
            $meta = (array) ($event->meta ?? []);

            foreach (['from_employee_id', 'to_employee_id'] as $key) {
                if (! empty($meta[$key])) {
                    $ids[] = (int) $meta[$key];
                }
            }

            if (empty($meta['holder_no']) && ! empty($meta['holder']) && $event->device_id) {
                $devices[] = (int) $event->device_id;
            }
        }

        $byId = $ids === [] ? [] : Employee::whereIn('id', array_unique($ids))
            ->whereNotNull('oracle_emp_no')
            ->pluck('oracle_emp_no', 'id')
            ->all();

        $byHolder = [];

        if ($devices !== []) {
            EmployeeAsset::query()
                ->join('employees', 'employees.id', '=', 'employee_assets.employee_id')
                ->whereIn('employee_assets.asset_id', array_unique($devices))
                ->whereNotNull('employees.oracle_emp_no')
                ->get(['employee_assets.asset_id as asset_id', 'employees.name as name', 'employees.oracle_emp_no as oracle_emp_no'])
                ->each(function ($row) use (&$byHolder) {
                    $byHolder[$row->asset_id.'|'.$row->name] = (string) $row->oracle_emp_no;
                });
        }

        return ['ids' => $byId, 'holders' => $byHolder];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array{ids: array<int, string>, holders: array<string, string>}  $codes
     */
    private function codeOf(array $meta, array $codes, string $side): ?string
    {
        if (! empty($meta[$side.'_employee_no'])) {
            return (string) $meta[$side.'_employee_no'];
        }

        $id = $meta[$side.'_employee_id'] ?? null;

        return $id ? ($codes['ids'][(int) $id] ?? null) : null;
    }

    /**
     * @param  array{ids: array<int, string>, holders: array<string, string>}  $codes
     * @return array<string, mixed>
     */
    private function row(AssetHistory $event, array $codes): array
    {
        $meta = (array) ($event->meta ?? []);
        $kind = self::EVENTS[$event->event_type] ?? $event->event_type;
        $device = $event->device;

        $from = $meta['from_employee'] ?? $meta['from_branch_name'] ?? null;
        $to = $meta['to_employee'] ?? $meta['to_branch_name'] ?? null;
        $fromNo = $this->codeOf($meta, $codes, 'from');
        $toNo = $this->codeOf($meta, $codes, 'to');

        if (in_array($kind, ['retire', 'scrap'], true)) {
            $from = $meta['holder'] ?? null;
            $to = null;
            $toNo = null;
            $fromNo = $meta['holder_no'] ?? ($from !== null ? ($codes['holders'][$event->device_id.'|'.$from] ?? null) : null);
        }

        $reasonCode = $meta['reason_code'] ?? null;
        $effective = $meta['retired_on'] ?? $meta['transfer_date'] ?? null;

        return [
            'id' => $event->id,
            // When it happened, for the period finance closes; `recorded_at` is when it was typed in.
            'date' => $effective ? CarbonImmutable::parse($effective) : ($event->created_at ? CarbonImmutable::instance($event->created_at) : null),
            'recorded_at' => $event->created_at,
            'kind' => $kind,
            'kind_label' => self::KINDS[$kind] ?? ucfirst((string) $kind),
            'device_id' => $device?->id,
            'asset_code' => $device?->asset_code,
            'oracle_asset_number' => $device?->oracle_asset_number,
            'name' => $device?->name,
            'type' => $device?->type,
            'serial_number' => $device?->serial_number,
            'branch' => $device?->branch?->name,
            'from' => $from,
            // The employee's Oracle number, which is what finance posts against.
            'from_no' => $fromNo,
            'to' => $to,
            'to_no' => $toNo,
            'storage_location' => $meta['storage_location'] ?? null,
            'condition' => $meta['condition'] ?? null,
            'reason_code' => $reasonCode,
            // A record written before the reason list existed only has its text.
            'reason_label' => AssetReasons::label($reasonCode),
            'reason' => $meta['reason'] ?? ($kind === 'retire' ? trim(str_replace('Retired:', '', (string) $event->description)) : null),
            'disposal_method' => $meta['disposal_method'] ?? null,
            'workflow_id' => $meta['workflow_id'] ?? null,
            'purchase_date' => $device?->purchase_date,
            'purchase_cost' => $device?->purchase_cost !== null ? (float) $device->purchase_cost : null,
            'currency' => $device?->currency ?: 'SAR',
            'by' => $event->user?->name,
        ];
    }
}
