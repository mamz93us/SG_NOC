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

    // Indices of expandable rows (non-section rows that have detail items).
    $expandableIndices = [];
    foreach ($rows as $i => $r) {
        if (empty($r['is_section']) && !empty($r['details'])) {
            $expandableIndices[] = $i;
        }
    }
@endphp

@section('content')
<style>
@media print {
    /* Keep employee summary row together with its expanded detail block */
    tbody tr { page-break-inside: avoid; }
    /* Preserve panel/badge colors on paper */
    .table-primary, .badge, .bg-white, .bg-light { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    /* Tighten spacing for paper */
    .container-fluid { padding: 0 !important; }
    .card { border: 0 !important; box-shadow: none !important; }
}
[x-cloak] { display: none !important; }
</style>
<div class="container-fluid py-4"
     x-data="{
        open: {},
        expandableIds: @js($expandableIndices),
        expandAll() { this.expandableIds.forEach(i => this.open[i] = true); },
        collapseAll() { this.open = {}; },
        printAll() { this.expandAll(); this.$nextTick(() => window.print()); }
     }">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Cost Report</h4>
        <div class="d-flex gap-2">
            @if(count($expandableIndices) > 0)
                <button type="button" @click="expandAll()" class="btn btn-sm btn-outline-info">
                    <i class="bi bi-arrows-expand me-1"></i>Expand All
                </button>
                <button type="button" @click="collapseAll()" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-arrows-collapse me-1"></i>Collapse All
                </button>
            @endif
            <a href="{{ route('admin.itam.reports.costs', array_merge(request()->all(), ['csv' => 1])) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            @if(count($expandableIndices) > 0)
                <button type="button" @click="printAll()" class="btn btn-sm btn-primary">
                    <i class="bi bi-printer me-1"></i>Expand &amp; Print
                </button>
            @else
                <button onclick="window.print()" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer me-1"></i>Print</button>
            @endif
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
                @php($colspan = $mode === 'branch' ? 8 : 9)
                <tbody>
                    @forelse($rows as $i => $r)
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
                            @php($expandable = !empty($r['details']))
                            <tr @if($expandable) @click="open[{{ $i }}] = !open[{{ $i }}]" style="cursor:pointer" @endif>
                                <td @class(['ps-4' => !empty($r['indent'])])>
                                    @if($expandable)
                                        <i class="bi me-1 text-muted"
                                           :class="open[{{ $i }}] ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                                    @endif
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

                            {{-- Expanded detail row --}}
                            @if($expandable)
                                <tr x-show="open[{{ $i }}]" x-cloak style="display:none">
                                    <td colspan="{{ $colspan }}" class="p-0">
                                        <div class="p-3" style="background:#f8fafc;border-top:2px solid #0d6efd">
                                            <div class="row g-3">
                                                {{-- DEVICES --}}
                                                <div class="col-md-4">
                                                    <h6 class="mb-2 text-primary">
                                                        <i class="bi bi-laptop me-1"></i>Devices ({{ count($r['details']['devices']) }})
                                                    </h6>
                                                    @forelse($r['details']['devices'] as $d)
                                                        <div class="border rounded p-2 mb-2 bg-white small">
                                                            <div class="d-flex justify-content-between">
                                                                <strong><code>{{ $d['asset_code'] ?? '—' }}</code></strong>
                                                                <span class="text-end"><strong>{{ $d['currency'] }} {{ number_format($d['cost'], 2) }}</strong></span>
                                                            </div>
                                                            <div>{{ $d['name'] }}</div>
                                                            <div class="text-muted">
                                                                <span class="badge bg-secondary">{{ $d['type'] }}</span>
                                                                @if($d['serial']) · S/N: {{ $d['serial'] }}@endif
                                                            </div>
                                                            @if($d['assigned'])
                                                                <div class="text-muted small">Assigned: {{ $d['assigned'] }}</div>
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <p class="text-muted small fst-italic">No devices assigned</p>
                                                    @endforelse
                                                </div>

                                                {{-- ACCESSORIES --}}
                                                <div class="col-md-4">
                                                    <h6 class="mb-2 text-secondary">
                                                        <i class="bi bi-box-seam me-1"></i>Accessories ({{ count($r['details']['accessories']) }})
                                                    </h6>
                                                    @forelse($r['details']['accessories'] as $a)
                                                        <div class="border rounded p-2 mb-2 bg-white small">
                                                            <div class="d-flex justify-content-between">
                                                                <strong>{{ $a['name'] }}</strong>
                                                                <span class="text-end">
                                                                    @if($a['cost'] > 0)
                                                                        <strong>{{ $a['currency'] }} {{ number_format($a['cost'], 2) }}</strong>
                                                                    @else
                                                                        <span class="text-muted">—</span>
                                                                    @endif
                                                                </span>
                                                            </div>
                                                            @if($a['category'])
                                                                <div class="text-muted">
                                                                    <span class="badge bg-light text-dark">{{ $a['category'] }}</span>
                                                                </div>
                                                            @endif
                                                            @if($a['assigned'])
                                                                <div class="text-muted small">Assigned: {{ $a['assigned'] }}</div>
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <p class="text-muted small fst-italic">No accessories assigned</p>
                                                    @endforelse
                                                </div>

                                                {{-- LICENSES --}}
                                                <div class="col-md-4">
                                                    <h6 class="mb-2 text-warning">
                                                        <i class="bi bi-key me-1"></i>Licenses ({{ count($r['details']['licenses']) }})
                                                    </h6>
                                                    @forelse($r['details']['licenses'] as $l)
                                                        <div class="border rounded p-2 mb-2 bg-white small">
                                                            <div class="d-flex justify-content-between">
                                                                <strong>{{ $l['name'] }}</strong>
                                                                <span class="text-end"><strong>{{ $l['currency'] }} {{ number_format($l['cost'], 2) }}</strong></span>
                                                            </div>
                                                            @if($l['vendor'])
                                                                <div class="text-muted">{{ $l['vendor'] }}</div>
                                                            @endif
                                                            @if($l['type'])
                                                                <div><span class="badge bg-light text-dark">{{ $l['type'] }}</span></div>
                                                            @endif
                                                            @if($l['assigned'])
                                                                <div class="text-muted small">Assigned: {{ $l['assigned'] }}</div>
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <p class="text-muted small fst-italic">No licenses assigned</p>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endif
                    @empty
                        <tr><td colspan="{{ $colspan }}" class="text-center py-5 text-muted">No data to display.</td></tr>
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
        <strong>Devices:</strong> only counts devices that have a <code>purchase_cost</code> set, excluding scrapped/retired. In branch mode: all devices with that <code>branch_id</code> (assigned or in store). In employee mode: currently-assigned devices only.<br>
        <strong>Accessories:</strong> only active assignments (no <code>returned_date</code>). In branch mode, branch is resolved via the holder's employee branch, or the attached device's branch.<br>
        <strong>Licenses:</strong> each assignment is attributed the <strong>full license cost</strong>. A 100-seat license at SAR 1,000 contributes SAR 1,000 to every employee who holds a seat.<br>
        <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i></span>
        <strong>Caveats:</strong>
        Shared licenses → grand total exceeds actual amount paid (one license counted on every holder's row).
        Branch mode and employee mode use slightly different scopes, so totals may not match: branch mode includes in-store devices (no employee) and device-attached license assignments; employee mode only includes items currently held by employees.
    </p>
</div>
@endsection
