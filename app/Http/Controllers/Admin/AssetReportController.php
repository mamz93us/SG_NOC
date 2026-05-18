<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\AccessoryAssignment;
use App\Models\AssetHistory;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\License;
use App\Models\LicenseAssignment;
use App\Models\WorkflowRequest;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetReportController extends Controller
{
    public function index()
    {
        $stats = [
            'total'     => Device::count(),
            'assigned'  => Device::where('status', 'assigned')->count(),
            'available' => Device::where('status', 'available')->count(),
            'in_store'  => Device::inStorage()->count(),
            'scrapped'  => Device::where('status', 'scrapped')->count(),
            'retired'   => Device::where('status', 'retired')->count(),
        ];

        return view('admin.itam.reports.index', compact('stats'));
    }

    public function allAssets(Request $request)
    {
        $query = Device::query()->with(['branch', 'currentAssignment.employee', 'supplier']);

        $this->applyCommonFilters($query, $request);

        if ($request->boolean('csv')) {
            return $this->streamCsv('all-assets-' . now()->format('Ymd-His'), $query->orderBy('asset_code')->get(), [
                'Asset Code', 'Name', 'Type', 'Status', 'Serial', 'Branch', 'Assigned To', 'Storage Location', 'Condition', 'Purchase Date', 'Purchase Cost',
            ], function ($d) {
                return [
                    $d->asset_code,
                    $d->name,
                    $d->type,
                    $d->status,
                    $d->serial_number,
                    $d->branch?->name,
                    $d->currentAssignment?->employee?->name,
                    $d->storage_location,
                    $d->condition,
                    optional($d->purchase_date)->format('Y-m-d'),
                    $d->purchase_cost,
                ];
            });
        }

        $devices  = $query->orderBy('asset_code')->paginate(100)->withQueryString();
        $branches = Branch::orderBy('name')->get();

        return view('admin.itam.reports.all-assets', compact('devices', 'branches'));
    }

    public function byBranch(Request $request)
    {
        $branches = Branch::orderBy('name')->get();
        $branchId = $request->integer('branch') ?: null;

        $query = Device::query()->with(['currentAssignment.employee']);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $grouped = $query->orderBy('branch_id')->orderBy('asset_code')->get()->groupBy('branch_id');

        if ($request->boolean('csv')) {
            $rows = $query->get();
            return $this->streamCsv('assets-by-branch-' . now()->format('Ymd-His'), $rows, [
                'Branch', 'Asset Code', 'Name', 'Type', 'Status', 'Assigned To', 'Storage Location',
            ], function ($d) {
                return [
                    $d->branch?->name ?? 'Unassigned',
                    $d->asset_code,
                    $d->name,
                    $d->type,
                    $d->status,
                    $d->currentAssignment?->employee?->name,
                    $d->storage_location,
                ];
            });
        }

        return view('admin.itam.reports.by-branch', compact('grouped', 'branches', 'branchId'));
    }

    public function byEmployee(Request $request)
    {
        $employees  = Employee::active()->orderBy('name')->get(['id', 'name']);
        $employeeId = $request->integer('employee') ?: null;

        $current = collect();
        $history = collect();
        $employee = null;

        if ($employeeId) {
            $employee = Employee::find($employeeId);
            $current  = EmployeeAsset::with('device.branch')
                ->where('employee_id', $employeeId)
                ->whereNull('returned_date')
                ->orderByDesc('assigned_date')
                ->get();
            $history  = EmployeeAsset::with('device.branch')
                ->where('employee_id', $employeeId)
                ->whereNotNull('returned_date')
                ->orderByDesc('returned_date')
                ->get();
        }

        if ($request->boolean('csv') && $employee) {
            $rows = $current->concat($history);
            return $this->streamCsv("assets-{$employee->name}-" . now()->format('Ymd-His'), $rows, [
                'Asset Code', 'Name', 'Assigned', 'Returned', 'Condition', 'Notes',
            ], function ($a) {
                return [
                    $a->device?->asset_code,
                    $a->device?->name,
                    optional($a->assigned_date)->format('Y-m-d'),
                    optional($a->returned_date)->format('Y-m-d'),
                    $a->condition,
                    $a->notes,
                ];
            });
        }

        return view('admin.itam.reports.by-employee', compact('employees', 'employee', 'current', 'history'));
    }

    public function transferHistory(Request $request)
    {
        $query = AssetHistory::with(['device.branch', 'user'])
            ->whereIn('event_type', ['transferred', 'moved_to_storage'])
            ->orderByDesc('created_at');

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to . ' 23:59:59');
        }
        if ($request->filled('branch')) {
            $branchId = (int) $request->branch;
            $query->where(function ($w) use ($branchId) {
                $w->where('meta->branch_id', $branchId)
                  ->orWhereHas('device', fn ($d) => $d->where('branch_id', $branchId));
            });
        }
        if ($request->filled('employee')) {
            $eid = (int) $request->employee;
            $query->where(function ($w) use ($eid) {
                $w->where('meta->from_employee_id', $eid)
                  ->orWhere('meta->to_employee_id', $eid);
            });
        }

        if ($request->boolean('csv')) {
            $rows = $query->get();
            return $this->streamCsv('transfer-history-' . now()->format('Ymd-His'), $rows, [
                'Date', 'Event', 'Asset Code', 'Asset Name', 'From Employee', 'To Employee', 'Branch', 'Storage Location', 'By',
            ], function ($e) {
                return [
                    $e->created_at?->format('Y-m-d H:i'),
                    $e->event_type,
                    $e->device?->asset_code,
                    $e->device?->name,
                    $e->meta['from_employee'] ?? null,
                    $e->meta['to_employee'] ?? null,
                    $e->meta['branch_name'] ?? $e->device?->branch?->name,
                    $e->meta['storage_location'] ?? null,
                    $e->user?->name,
                ];
            });
        }

        $events   = $query->paginate(50)->withQueryString();
        $branches = Branch::orderBy('name')->get();
        $employees = Employee::active()->orderBy('name')->get(['id', 'name']);

        return view('admin.itam.reports.transfers', compact('events', 'branches', 'employees'));
    }

    public function scrapHistory(Request $request)
    {
        $from = $request->filled('from') ? $request->from : null;
        $to = $request->filled('to') ? $request->to . ' 23:59:59' : null;
        $branchId = $request->filled('branch') ? (int) $request->branch : null;

        // ─── Device scrap events come from asset_histories ───────────────
        $deviceQuery = AssetHistory::with(['device.branch', 'user'])
            ->where('event_type', 'scrapped');

        if ($from) {
            $deviceQuery->where('created_at', '>=', $from);
        }
        if ($to) {
            $deviceQuery->where('created_at', '<=', $to);
        }
        if ($branchId) {
            $deviceQuery->whereHas('device', fn ($d) => $d->where('branch_id', $branchId));
        }

        $deviceRows = $deviceQuery->get()->map(fn ($e) => [
            'date' => $e->created_at,
            'kind' => 'device',
            'asset_code' => $e->device?->asset_code,
            'name' => $e->device?->name,
            'qty' => 1,
            'branch' => $e->device?->branch?->name,
            'disposal_method' => $e->meta['disposal_method'] ?? null,
            'workflow_id' => $e->meta['workflow_id'] ?? null,
            'by' => $e->user?->name,
        ]);

        // ─── Accessory scrap events come from approved scrap workflows ──
        $wfQuery = WorkflowRequest::with(['requester', 'branch'])
            ->where('type', 'asset_scrap')
            ->where('status', 'approved');

        if ($from) {
            $wfQuery->where('updated_at', '>=', $from);
        }
        if ($to) {
            $wfQuery->where('updated_at', '<=', $to);
        }
        if ($branchId) {
            $wfQuery->where('branch_id', $branchId);
        }

        $accessoryRows = collect();
        $workflows = $wfQuery->get();
        $allAccIds = $workflows->flatMap(fn ($wf) => $wf->payload['accessory_ids'] ?? [])->unique()->all();
        $accessoryLookup = Accessory::with('branch')->whereIn('id', $allAccIds)->get()->keyBy('id');

        foreach ($workflows as $wf) {
            $accIds = $wf->payload['accessory_ids'] ?? [];
            if (empty($accIds)) {
                continue;
            }
            $qtyMap = $wf->payload['accessory_qty'] ?? [];

            foreach ($accIds as $accId) {
                $a = $accessoryLookup->get($accId);
                if (! $a) {
                    continue;
                }
                if ($branchId && $a->branch_id !== $branchId) {
                    continue;
                }
                $accessoryRows->push([
                    'date' => $wf->updated_at,
                    'kind' => 'accessory',
                    'asset_code' => $a->asset_code,
                    'name' => $a->name,
                    'qty' => $qtyMap[$accId] ?? 1,
                    'branch' => $a->branch?->name,
                    'disposal_method' => $wf->payload['disposal_method'] ?? null,
                    'workflow_id' => $wf->id,
                    'by' => $wf->requester?->name,
                ]);
            }
        }

        $merged = $deviceRows->concat($accessoryRows)
            ->sortByDesc('date')
            ->values();

        if ($request->boolean('csv')) {
            return $this->streamCsv('scrap-history-'.now()->format('Ymd-His'), $merged, [
                'Date', 'Kind', 'Asset Code', 'Asset Name', 'Qty', 'Branch', 'Disposal Method', 'Workflow #', 'By',
            ], function ($r) {
                return [
                    $r['date']?->format('Y-m-d H:i'),
                    $r['kind'],
                    $r['asset_code'],
                    $r['name'],
                    $r['qty'],
                    $r['branch'],
                    $r['disposal_method'],
                    $r['workflow_id'],
                    $r['by'],
                ];
            });
        }

        $perPage = 50;
        $page = max(1, (int) $request->input('page', 1));
        $events = new LengthAwarePaginator(
            $merged->forPage($page, $perPage)->values(),
            $merged->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $branches = Branch::orderBy('name')->get();

        return view('admin.itam.reports.scraps', compact('events', 'branches'));
    }

    // ─────────────────────────────────────────────────────────────
    // Cost Report — by branch / by employee / by branch+employee
    // ─────────────────────────────────────────────────────────────

    public function costs(Request $request)
    {
        $mode = $request->get('mode', 'branch');
        if (!in_array($mode, ['branch', 'employee', 'branch_employee'])) {
            $mode = 'branch';
        }

        // Per-license full cost: each assignment gets the full license cost.
        // Cross-row totals will exceed the actual amount paid when licenses are shared.
        $licenseCosts = License::select('id', 'cost', 'currency')->get()
            ->mapWithKeys(function ($l) {
                return [$l->id => [
                    'cost'     => (float) ($l->cost ?? 0),
                    'currency' => $l->currency ?? 'USD',
                ]];
            });

        $branches  = Branch::orderBy('name')->get();
        $selectedBranchId = $request->integer('branch') ?: null;

        $rows = match ($mode) {
            'branch'          => $this->costsByBranch($licenseCosts),
            'employee'        => $this->costsByEmployee($licenseCosts, $selectedBranchId),
            'branch_employee' => $this->costsByBranchEmployee($licenseCosts, $selectedBranchId),
        };

        // Grand totals across all rows — but in drill-down mode, $rows contains BOTH
        // branch section headers (with their subtotals) AND the individual employee rows.
        // Skip the section headers so we don't double-count.
        $grand = $this->emptyTotals();
        foreach ($rows as $r) {
            if (!empty($r['is_section'])) continue;
            foreach (['devices', 'accessories', 'licenses', 'total'] as $bucket) {
                foreach ($r[$bucket] as $cur => $v) {
                    $grand[$bucket][$cur] = ($grand[$bucket][$cur] ?? 0) + $v;
                }
            }
        }

        if ($request->boolean('csv')) {
            return $this->streamCostsCsv($mode, $rows);
        }

        return view('admin.itam.reports.costs', compact(
            'rows', 'grand', 'mode', 'branches', 'selectedBranchId'
        ));
    }

    private function costsByBranch(Collection $licenseCosts): array
    {
        $branches = Branch::orderBy('name')->get();
        $rows = [];

        // Devices grouped by branch — exclude scrapped/retired (no longer assets).
        // Counts include all devices, even without purchase_cost; sum only includes those with cost.
        $deviceCosts = Device::whereNotIn('status', ['scrapped', 'retired'])
            ->selectRaw('branch_id, currency, COALESCE(sum(purchase_cost), 0) as total, count(*) as cnt')
            ->groupBy('branch_id', 'currency')
            ->get()
            ->groupBy('branch_id');

        // Active accessory assignments grouped by branch.
        // Branch is resolved via employee.branch_id (preferred) or device.branch_id (when attached to a device).
        $accessoryRows = AccessoryAssignment::with(['accessory', 'employee', 'device'])
            ->whereNull('returned_date')
            ->get();

        $accByBranch = [];
        foreach ($accessoryRows as $a) {
            $branchId = $a->employee?->branch_id ?? $a->device?->branch_id;
            if (!$branchId) continue;
            $cur = $a->accessory?->currency ?? 'USD';
            $cost = (float) ($a->accessory?->purchase_cost ?? 0);
            $accByBranch[$branchId][$cur]['total'] = ($accByBranch[$branchId][$cur]['total'] ?? 0) + $cost;
            $accByBranch[$branchId][$cur]['cnt']   = ($accByBranch[$branchId][$cur]['cnt'] ?? 0) + 1;
        }

        // License assignments via employee or device — map to branch.
        // Each assignment gets the FULL license cost.
        $licenseRows = LicenseAssignment::with('license')->get();
        $licByBranch = [];
        foreach ($licenseRows as $la) {
            $branchId = $this->branchOfAssignable($la);
            if (!$branchId) continue;
            $info = $licenseCosts[$la->license_id] ?? null;
            if (!$info) continue;
            $licByBranch[$branchId][$info['currency']]['total']
                = ($licByBranch[$branchId][$info['currency']]['total'] ?? 0) + $info['cost'];
            $licByBranch[$branchId][$info['currency']]['cnt']
                = ($licByBranch[$branchId][$info['currency']]['cnt'] ?? 0) + 1;
        }

        foreach ($branches as $b) {
            $devices = $this->emptyBucket();
            $deviceCount = 0;
            if (isset($deviceCosts[$b->id])) {
                foreach ($deviceCosts[$b->id] as $row) {
                    $cur = $row->currency ?? 'USD';
                    $devices[$cur] = ($devices[$cur] ?? 0) + (float) $row->total;
                    $deviceCount += (int) $row->cnt;
                }
            }
            $accessories = $this->emptyBucket();
            $accCount = 0;
            foreach (($accByBranch[$b->id] ?? []) as $cur => $entry) {
                $accessories[$cur] = $entry['total'];
                $accCount += $entry['cnt'];
            }
            $licenses = $this->emptyBucket();
            $licCount = 0;
            foreach (($licByBranch[$b->id] ?? []) as $cur => $entry) {
                $licenses[$cur] = $entry['total'];
                $licCount += $entry['cnt'];
            }

            $rows[] = [
                'label'       => $b->name,
                'sublabel'    => null,
                'devices'     => $devices,
                'accessories' => $accessories,
                'licenses'    => $licenses,
                'total'       => $this->sumBuckets($devices, $accessories, $licenses),
                'counts'      => [
                    'devices'     => $deviceCount,
                    'accessories' => $accCount,
                    'licenses'    => $licCount,
                ],
            ];
        }

        // "Unassigned" row for devices not linked to a branch — exclude scrapped/retired
        $unassignedDevices = Device::whereNull('branch_id')
            ->whereNotIn('status', ['scrapped', 'retired'])
            ->selectRaw('currency, COALESCE(sum(purchase_cost), 0) as total, count(*) as cnt')
            ->groupBy('currency')
            ->get();

        // Sort branches by total cost descending (highest spenders first).
        usort($rows, fn ($a, $b) => array_sum($b['total']) <=> array_sum($a['total']));

        if ($unassignedDevices->isNotEmpty()) {
            $devices = $this->emptyBucket();
            $deviceCount = 0;
            foreach ($unassignedDevices as $u) {
                $devices[$u->currency ?? 'USD'] = ($devices[$u->currency ?? 'USD'] ?? 0) + (float) $u->total;
                $deviceCount += (int) $u->cnt;
            }
            $rows[] = [
                'label'       => 'Unassigned (no branch)',
                'sublabel'    => 'Universal Store',
                'devices'     => $devices,
                'accessories' => $this->emptyBucket(),
                'licenses'    => $this->emptyBucket(),
                'total'       => $devices,
                'counts'      => ['devices' => $deviceCount, 'accessories' => 0, 'licenses' => 0],
            ];
        }

        return $rows;
    }

    private function costsByEmployee(Collection $licenseCosts, ?int $branchFilter = null): array
    {
        $employees = Employee::with('branch')
            ->active()
            ->when($branchFilter, fn ($q) => $q->where('branch_id', $branchFilter))
            ->orderBy('name')
            ->get();

        // Pre-fetch full assignment records grouped by employee (eager-loaded so we
        // can both aggregate and itemize without N+1 queries).
        // Include ALL active assignments so counts match the employee profile —
        // devices without a purchase_cost contribute 0 to the cost total but are still listed.
        $deviceAssignmentsByEmp = EmployeeAsset::with('device')
            ->whereNull('returned_date')
            ->whereHas('device', function ($q) {
                $q->whereNotIn('status', ['scrapped', 'retired']);
            })
            ->get()
            ->groupBy('employee_id');

        $accessoryAssignmentsByEmp = AccessoryAssignment::with('accessory')
            ->whereNull('returned_date')
            ->whereNotNull('employee_id')
            ->get()
            ->groupBy('employee_id');

        $licenseAssignmentsByEmp = LicenseAssignment::with('license')
            ->where('assignable_type', Employee::class)
            ->get()
            ->groupBy('assignable_id');

        $rows = [];
        foreach ($employees as $emp) {
            // ── Devices ─────────────────────────────────────────────
            $devices = $this->emptyBucket();
            $deviceCount = 0;
            $deviceList = [];
            foreach (($deviceAssignmentsByEmp[$emp->id] ?? collect()) as $ea) {
                if (!$ea->device) continue;
                $cur  = $ea->device->currency ?? 'USD';
                $cost = (float) ($ea->device->purchase_cost ?? 0);
                if ($cost > 0) {
                    $devices[$cur] = ($devices[$cur] ?? 0) + $cost;
                }
                $deviceCount++;  // Count every assigned device, even without cost data.
                $deviceList[] = [
                    'name'       => $ea->device->name,
                    'asset_code' => $ea->device->asset_code,
                    'type'       => $ea->device->type,
                    'serial'     => $ea->device->serial_number,
                    'cost'       => $cost,
                    'currency'   => $cur,
                    'assigned'   => $ea->assigned_date?->format('d M Y'),
                    'device_id'  => $ea->device->id,
                ];
            }

            // ── Accessories ─────────────────────────────────────────
            $accessories = $this->emptyBucket();
            $accCount = 0;
            $accessoryList = [];
            foreach (($accessoryAssignmentsByEmp[$emp->id] ?? collect()) as $aa) {
                $cur  = $aa->accessory?->currency ?? 'USD';
                $cost = (float) ($aa->accessory?->purchase_cost ?? 0);
                if ($cost > 0) {
                    $accessories[$cur] = ($accessories[$cur] ?? 0) + $cost;
                }
                $accCount++;
                $accessoryList[] = [
                    'name'     => $aa->accessory?->name ?? '—',
                    'category' => $aa->accessory?->category ?? null,
                    'cost'     => $cost,
                    'currency' => $cur,
                    'assigned' => $aa->assigned_date?->format('d M Y'),
                ];
            }

            // ── Licenses ────────────────────────────────────────────
            $licenses = $this->emptyBucket();
            $licCount = 0;
            $licenseList = [];
            foreach (($licenseAssignmentsByEmp[$emp->id] ?? collect()) as $la) {
                $info = $licenseCosts[$la->license_id] ?? null;
                if (!$info) continue;
                if ($info['cost'] > 0) {
                    $licenses[$info['currency']] = ($licenses[$info['currency']] ?? 0) + $info['cost'];
                }
                $licCount++;
                $licenseList[] = [
                    'name'     => $la->license?->license_name ?? '—',
                    'vendor'   => $la->license?->vendor ?? null,
                    'type'     => $la->license?->license_type ?? null,
                    'cost'     => $info['cost'],
                    'currency' => $info['currency'],
                    'assigned' => $la->assigned_date?->format('d M Y'),
                ];
            }

            // Only include employees who actually have something
            if ($deviceCount + $accCount + $licCount === 0) continue;

            $rows[] = [
                'label'       => $emp->name,
                'sublabel'    => $emp->branch?->name,
                'devices'     => $devices,
                'accessories' => $accessories,
                'licenses'    => $licenses,
                'total'       => $this->sumBuckets($devices, $accessories, $licenses),
                'counts'      => [
                    'devices'     => $deviceCount,
                    'accessories' => $accCount,
                    'licenses'    => $licCount,
                ],
                'details'     => [
                    'devices'     => $deviceList,
                    'accessories' => $accessoryList,
                    'licenses'    => $licenseList,
                ],
            ];
        }

        // Sort by total cost descending so the heaviest users are at the top.
        usort($rows, fn ($a, $b) => array_sum($b['total']) <=> array_sum($a['total']));

        return $rows;
    }

    private function costsByBranchEmployee(Collection $licenseCosts, ?int $branchFilter = null): array
    {
        $employees = $this->costsByEmployee($licenseCosts, $branchFilter);

        // With a branch filter, drill-down is redundant — return the flat employee list.
        if ($branchFilter) {
            return $employees;
        }

        // Group rows by branch name; "(No branch)" goes last.
        $grouped = [];
        foreach ($employees as $row) {
            $key = $row['sublabel'] ?: '(No branch)';
            $grouped[$key][] = $row;
        }

        // Sort each group by total cost (any currency) descending; then sort branches alphabetically, "(No branch)" last.
        $sumTotal = fn ($r) => array_sum($r['total']);
        foreach ($grouped as &$rows) {
            usort($rows, fn ($a, $b) => $sumTotal($b) <=> $sumTotal($a));
        }
        unset($rows);

        uksort($grouped, function ($a, $b) {
            if ($a === '(No branch)') return 1;
            if ($b === '(No branch)') return -1;
            return strcasecmp($a, $b);
        });

        // Build sectioned rows: section header (with subtotal) + indented employee rows.
        $out = [];
        foreach ($grouped as $branchName => $empRows) {
            $sub = $this->emptyTotals();
            $counts = ['devices' => 0, 'accessories' => 0, 'licenses' => 0];
            foreach ($empRows as $r) {
                foreach (['devices', 'accessories', 'licenses', 'total'] as $k) {
                    foreach ($r[$k] as $cur => $v) {
                        $sub[$k][$cur] = ($sub[$k][$cur] ?? 0) + $v;
                    }
                }
                $counts['devices']     += $r['counts']['devices'];
                $counts['accessories'] += $r['counts']['accessories'];
                $counts['licenses']    += $r['counts']['licenses'];
            }

            $out[] = [
                'is_section'  => true,
                'label'       => $branchName,
                'sublabel'    => count($empRows) . ' ' . (count($empRows) === 1 ? 'employee' : 'employees'),
                'devices'     => $sub['devices'],
                'accessories' => $sub['accessories'],
                'licenses'    => $sub['licenses'],
                'total'       => $sub['total'],
                'counts'      => $counts,
            ];

            foreach ($empRows as $r) {
                $r['is_section'] = false;
                $r['indent']     = true;
                $out[] = $r;
            }
        }

        return $out;
    }

    private function branchOfAssignable(LicenseAssignment $la): ?int
    {
        if ($la->assignable_type === Employee::class) {
            return Employee::find($la->assignable_id)?->branch_id;
        }
        if ($la->assignable_type === Device::class) {
            return Device::find($la->assignable_id)?->branch_id;
        }
        return null;
    }

    private function emptyBucket(): array { return []; }

    private function emptyTotals(): array
    {
        return [
            'devices'     => [],
            'accessories' => [],
            'licenses'    => [],
            'total'       => [],
        ];
    }

    private function sumBuckets(array ...$buckets): array
    {
        $out = [];
        foreach ($buckets as $b) {
            foreach ($b as $cur => $v) {
                $out[$cur] = ($out[$cur] ?? 0) + $v;
            }
        }
        return $out;
    }

    private function streamCostsCsv(string $mode, array $rows): StreamedResponse
    {
        $filename = "costs-{$mode}-" . now()->format('Ymd-His');
        return response()->streamDownload(function () use ($rows) {
            $h = fopen('php://output', 'w');
            fputcsv($h, [
                'Row Type', 'Label', 'Sublabel',
                'Devices Count', 'Devices Cost',
                'Accessories Count', 'Accessories Cost',
                'Licenses Count', 'Licenses Cost',
                'Total Cost',
            ]);
            foreach ($rows as $r) {
                fputcsv($h, [
                    !empty($r['is_section']) ? 'Branch Subtotal' : 'Row',
                    $r['label'],
                    $r['sublabel'] ?? '',
                    $r['counts']['devices'],
                    $this->bucketToString($r['devices']),
                    $r['counts']['accessories'],
                    $this->bucketToString($r['accessories']),
                    $r['counts']['licenses'],
                    $this->bucketToString($r['licenses']),
                    $this->bucketToString($r['total']),
                ]);
            }
            fclose($h);
        }, $filename . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function bucketToString(array $bucket): string
    {
        if (empty($bucket)) return '0';
        $parts = [];
        foreach ($bucket as $cur => $v) {
            $parts[] = $cur . ' ' . number_format($v, 2);
        }
        return implode(' + ', $parts);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function applyCommonFilters($query, Request $request): void
    {
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('condition')) {
            $query->where('condition', $request->condition);
        }
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($w) use ($q) {
                $w->where('asset_code', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$q}%")
                  ->orWhere('serial_number', 'like', "%{$q}%");
            });
        }
    }

    private function streamCsv(string $filename, $rows, array $headers, callable $rowMap): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $headers, $rowMap) {
            $h = fopen('php://output', 'w');
            fputcsv($h, $headers);
            foreach ($rows as $row) {
                fputcsv($h, $rowMap($row));
            }
            fclose($h);
        }, $filename . '.csv', ['Content-Type' => 'text/csv']);
    }
}
