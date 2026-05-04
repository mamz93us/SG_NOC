<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssetHistory;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use Illuminate\Http\Request;
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
        $query = AssetHistory::with(['device.branch', 'user'])
            ->where('event_type', 'scrapped')
            ->orderByDesc('created_at');

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to . ' 23:59:59');
        }
        if ($request->filled('branch')) {
            $branchId = (int) $request->branch;
            $query->whereHas('device', fn ($d) => $d->where('branch_id', $branchId));
        }

        if ($request->boolean('csv')) {
            $rows = $query->get();
            return $this->streamCsv('scrap-history-' . now()->format('Ymd-His'), $rows, [
                'Date', 'Asset Code', 'Asset Name', 'Branch', 'Disposal Method', 'Workflow #', 'By',
            ], function ($e) {
                return [
                    $e->created_at?->format('Y-m-d H:i'),
                    $e->device?->asset_code,
                    $e->device?->name,
                    $e->device?->branch?->name,
                    $e->meta['disposal_method'] ?? null,
                    $e->meta['workflow_id'] ?? null,
                    $e->user?->name,
                ];
            });
        }

        $events   = $query->paginate(50)->withQueryString();
        $branches = Branch::orderBy('name')->get();

        return view('admin.itam.reports.scraps', compact('events', 'branches'));
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
