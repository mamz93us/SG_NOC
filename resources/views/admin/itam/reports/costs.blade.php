@extends('layouts.admin')
@section('title', 'Cost Report')

@php
    // Helper: render a multi-currency bucket as inline HTML. Returns "—" when empty.
    $fmtBucket = function ($bucket) {
        if (empty($bucket)) return '<span class="text-muted">—</span>';
        $parts = [];
        foreach ($bucket as $cur => $v) {
            if ((float)$v <= 0) continue;
            $parts[] = '<span class="text-nowrap"><strong>' . e($cur) . '</strong> ' . number_format($v, 2) . '</span>';
        }
        return empty($parts) ? '<span class="text-muted">—</span>' : implode('<br>', $parts);
    };
@endphp

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Cost Report</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.itam.reports.costs', array_merge(request()->all(), ['csv' => 1])) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer me-1"></i>Print</button>
            <a href="{{ route('admin.itam.reports.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>

    {{-- Mode Selector --}}
    <div class="card border-0 shadow-sm mb-3 d-print-none">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">View Mode</label>
                    <select name="mode" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="branch"           @selected($mode === 'branch')>By Branch (totals per branch)</option>
                        <option value="employee"         @selected($mode === 'employee')>By Employee (totals per employee)</option>
                        <option value="branch_employee"  @selected($mode === 'branch_employee')>By Branch &amp; Employee (drill-down)</option>
                    </select>
                </div>
                @if($mode === 'employee' || $mode === 'branch_employee')
                    <div class="col-md-4">
                        <label class="form-label small">Filter by Branch</label>
                        <select name="branch" class="form-select form-select-sm">
                            <option value="">All branches</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected($selectedBranchId === $b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-sm btn-primary w-100">Apply</button>
                    </div>
                @endif
            </form>
        </div>
    </div>

    {{-- Grand Totals Summary --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-md-3 text-center">
                    <div class="small text-muted text-uppercase">Devices Total</div>
                    <div class="h5 mb-0">{!! $fmtBucket($grand['devices']) !!}</div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="small text-muted text-uppercase">Accessories Total</div>
                    <div class="h5 mb-0">{!! $fmtBucket($grand['accessories']) !!}</div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="small text-muted text-uppercase">Licenses Total</div>
                    <div class="h5 mb-0">{!! $fmtBucket($grand['licenses']) !!}</div>
                </div>
                <div class="col-md-3 text-center border-start">
                    <div class="small text-muted text-uppercase">Grand Total</div>
                    <div class="h4 mb-0 text-primary">{!! $fmtBucket($grand['total']) !!}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Rows --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <strong>
                @if($mode === 'branch')
                    Branches
                @elseif($mode === 'employee')
                    Employees with Assigned Assets
                @else
                    @if($selectedBranchId)
                        Employees in {{ $branches->firstWhere('id', $selectedBranchId)?->name }}
                    @else
                        Employees grouped by Branch
                    @endif
                @endif
                @php
                    $displayCount = $mode === 'branch_employee'
                        ? collect($rows)->where('is_section', false)->count()
                        : count($rows);
                @endphp
                <span class="badge bg-secondary ms-2">{{ $displayCount }}</span>
            </strong>
            <small class="text-muted ms-2">
                @if($mode === 'branch_employee')
                    Sub-totals shown per branch. Within each branch, employees are sorted by total cost descending.
                @else
                    Each assignment is attributed the full license cost. Rows sorted by total cost descending.
                @endif
            </small>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>
                            @if($mode === 'branch') Branch
                            @else Employee
                            @endif
                        </th>
                        @if($mode !== 'branch')
                            <th>Branch</th>
                        @endif
                        <th class="text-center">Devices</th>
                        <th class="text-end">Devices Cost</th>
                        <th class="text-center">Accessories</th>
                        <th class="text-end">Accessories Cost</th>
                        <th class="text-center">Licenses</th>
                        <th class="text-end">Licenses Cost</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        @if(!empty($r['is_section']))
                            {{-- Branch section header row (drill-down mode) --}}
                            <tr class="table-primary">
                                <td>
                                    <i class="bi bi-building me-1"></i>
                                    <strong>{{ $r['label'] }}</strong>
                                    <small class="text-muted ms-2">({{ $r['sublabel'] }})</small>
                                </td>
                                @if($mode !== 'branch')
                                    <td></td>
                                @endif
                                <td class="text-center"><strong>{{ $r['counts']['devices'] }}</strong></td>
                                <td class="text-end"><strong>{!! $fmtBucket($r['devices']) !!}</strong></td>
                                <td class="text-center"><strong>{{ $r['counts']['accessories'] }}</strong></td>
                                <td class="text-end"><strong>{!! $fmtBucket($r['accessories']) !!}</strong></td>
                                <td class="text-center"><strong>{{ $r['counts']['licenses'] }}</strong></td>
                                <td class="text-end"><strong>{!! $fmtBucket($r['licenses']) !!}</strong></td>
                                <td class="text-end text-primary"><strong>{!! $fmtBucket($r['total']) !!}</strong></td>
                            </tr>
                        @else
                            <tr>
                                <td @class(['ps-4' => !empty($r['indent'])])>
                                    @if(!empty($r['indent']))
                                        <i class="bi bi-person text-muted me-1"></i>
                                    @endif
                                    <strong>{{ $r['label'] }}</strong>
                                    @if($r['sublabel'] && $mode === 'branch')
                                        <small class="d-block text-muted">{{ $r['sublabel'] }}</small>
                                    @endif
                                </td>
                                @if($mode !== 'branch')
                                    <td>{{ empty($r['indent']) ? ($r['sublabel'] ?? '—') : '' }}</td>
                                @endif
                                <td class="text-center"><span class="badge bg-info">{{ $r['counts']['devices'] }}</span></td>
                                <td class="text-end">{!! $fmtBucket($r['devices']) !!}</td>
                                <td class="text-center"><span class="badge bg-secondary">{{ $r['counts']['accessories'] }}</span></td>
                                <td class="text-end">{!! $fmtBucket($r['accessories']) !!}</td>
                                <td class="text-center"><span class="badge bg-warning text-dark">{{ $r['counts']['licenses'] }}</span></td>
                                <td class="text-end">{!! $fmtBucket($r['licenses']) !!}</td>
                                <td class="text-end"><strong>{!! $fmtBucket($r['total']) !!}</strong></td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="{{ $mode === 'branch' ? 8 : 9 }}" class="text-center py-5 text-muted">No data to display.</td></tr>
                    @endforelse
                </tbody>
                @if(count($rows) > 0)
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="{{ $mode === 'branch' ? 2 : 3 }}">Grand Total</td>
                            <td class="text-end">{!! $fmtBucket($grand['devices']) !!}</td>
                            <td></td>
                            <td class="text-end">{!! $fmtBucket($grand['accessories']) !!}</td>
                            <td></td>
                            <td class="text-end">{!! $fmtBucket($grand['licenses']) !!}</td>
                            <td class="text-end text-primary">{!! $fmtBucket($grand['total']) !!}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    <p class="text-muted small mt-3">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Devices:</strong> in branch mode, all devices with that branch_id (assigned or in store). In employee mode, currently-assigned devices only.<br>
        <strong>Accessories:</strong> only counts currently-assigned units (active assignments).<br>
        <strong>Licenses:</strong> each assignment is attributed the <strong>full license cost</strong>. A 100-seat license at SAR 1,000 contributes SAR 1,000 to every employee who holds a seat.
        <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Note:</span> with shared licenses, the grand total will exceed the actual amount paid (a single license can be counted on multiple employee rows).
    </p>
</div>
@endsection
