@extends('layouts.admin')
@section('title', 'License Cost by Department')

@section('content')
@php
    // One money cell: the combined figure in the chosen currency, with the per-currency facts under it.
    $cell = function (array $byCurrency, array $combined) {
        if ($byCurrency === []) {
            return '<span class="text-muted">—</span>';
        }
        $single = count($byCurrency) === 1 && array_key_first($byCurrency) === $combined['currency'];
        $html = '<div class="fw-semibold">'.($single ? '' : '≈ ').e($combined['currency']).' '.number_format($combined['total'], 2).'</div>';
        if (! $single) {
            foreach ($byCurrency as $currency => $amount) {
                $html .= '<div class="small text-muted">'.e($currency).' '.number_format($amount, 2).'</div>';
            }
        }

        return $html;
    };
    $unit = $groupBy === 'branch' ? 'Branch' : 'Department';
    $filters = collect([$vendor !== '' ? $vendor : null, $branch !== '' ? ($branchOptions[$branch] ?? $branch) : null])->filter();
@endphp
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>License Cost by {{ $unit }} — {{ $month->format('F Y') }}@if($filters->isNotEmpty()) <span class="text-muted fs-6">· {{ $filters->implode(' · ') }}</span>@endif</h4>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.itam.reports.subscriptions', ['month' => $month->format('Y-m'), 'display' => $display]) }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-robot me-1"></i>Usage
            </a>
            <a href="{{ route('admin.itam.reports.subscription-payments', ['month' => $month->format('Y-m'), 'display' => $display]) }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-cash-coin me-1"></i>Payments Due
            </a>
            <a href="{{ route('admin.itam.reports.subscriptions-by-department', array_merge(request()->query(), ['csv' => 1])) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>CSV
            </a>
            <a href="{{ route('admin.itam.reports.subscriptions-by-department', array_merge(request()->query(), ['csv' => 'detail'])) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>CSV (per seat)
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Print</button>
            <a href="{{ route('admin.itam.reports.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>

    <div class="d-none d-print-block mb-3">
        <h4 class="mb-1">License Cost by {{ $unit }} — {{ $month->format('F Y') }}@if($filters->isNotEmpty()) · {{ $filters->implode(' · ') }}@endif</h4>
        <div class="small text-muted">Samir Group IT · generated {{ now()->format('d M Y H:i') }}</div>
    </div>

    <p class="text-muted small mb-3">
        Every license that costs something (Microsoft 365, AI tools, Adobe, Autodesk and the rest), charged to the department
        and branch of the person holding each seat. A second mailbox linked to a person counts under that person's main record.
        <strong>Per year</strong> and <strong>Monthly</strong> are the run rate of recurring licenses, what the department costs
        to keep running. <strong>Due {{ $month->format('M') }}</strong> is each seat's share of the licenses that renew in
        {{ $month->format('F Y') }}. <strong>One-time</strong> is licenses bought once, at their purchase price; it is never
        added to the run rate.
    </p>

    <div class="alert alert-light border small py-2">
        <i class="bi bi-info-circle me-1"></i>
        <strong>The split is an allocation, not a set of payments.</strong> A license is one indivisible
        charge, and finance still pays it once, from the
        <a href="{{ route('admin.itam.reports.subscription-payments', ['month' => $month->format('Y-m')]) }}">Payments Due</a>
        report. Unfiltered, the shares here add back up to the same totals, so they can be used for recharge.
    </div>

    @if($filters->isNotEmpty())
    <div class="alert alert-info small py-2 d-print-none">
        <i class="bi bi-funnel me-1"></i>
        Showing only <strong>{{ $filters->implode(' · ') }}</strong>: every total below covers those seats alone@if($branch !== ''), and seats nobody holds belong to no branch so they are left out@endif.
        <a href="{{ route('admin.itam.reports.subscriptions-by-department', array_filter(['month' => $month->format('Y-m'), 'type' => $type, 'display' => $display, 'group' => $groupBy === 'branch' ? 'branch' : null])) }}" class="alert-link">Clear filters</a>
    </div>
    @endif

    {{-- Filters --}}
    <form method="GET" class="mb-3 d-print-none">
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <label class="small text-muted mb-0">Month</label>
            <select name="month" class="form-select form-select-sm" style="max-width:180px" onchange="this.form.submit()">
                @foreach($monthOptions as $m)
                <option value="{{ $m->format('Y-m') }}" {{ $m->format('Y-m') === $month->format('Y-m') ? 'selected' : '' }}>{{ $m->format('F Y') }}</option>
                @endforeach
            </select>
            <label class="small text-muted mb-0 ms-2">Group by</label>
            <select name="group" class="form-select form-select-sm" style="max-width:140px" onchange="this.form.submit()">
                <option value="department" {{ $groupBy === 'department' ? 'selected' : '' }}>Department</option>
                <option value="branch" {{ $groupBy === 'branch' ? 'selected' : '' }}>Branch</option>
            </select>
            <label class="small text-muted mb-0 ms-2">Vendor</label>
            <select name="vendor" class="form-select form-select-sm" style="max-width:180px" onchange="this.form.submit()">
                <option value="">All vendors</option>
                @foreach($vendorOptions as $option)
                <option value="{{ $option }}" {{ strcasecmp($vendor, $option) === 0 ? 'selected' : '' }}>{{ $option }}</option>
                @endforeach
            </select>
            <label class="small text-muted mb-0 ms-2">Branch</label>
            <select name="branch" class="form-select form-select-sm" style="max-width:200px" onchange="this.form.submit()">
                <option value="">All branches</option>
                @foreach($branchOptions as $key => $name)
                <option value="{{ $key }}" {{ $branch === $key ? 'selected' : '' }}>{{ $name }}</option>
                @endforeach
            </select>
            <label class="small text-muted mb-0 ms-2">Type</label>
            <select name="type" class="form-select form-select-sm" style="max-width:180px" onchange="this.form.submit()">
                @foreach($typeOptions as $value => $label)
                <option value="{{ $value }}" {{ $type === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <label class="small text-muted mb-0 ms-2">Total in</label>
            <select name="display" class="form-select form-select-sm" style="max-width:110px" onchange="this.form.submit()">
                @foreach($displayOptions as $code)
                <option value="{{ $code }}" {{ $display === $code ? 'selected' : '' }}>{{ $code }}</option>
                @endforeach
            </select>
            <a href="{{ route('admin.itam.exchange-rates.index') }}" class="btn btn-sm btn-outline-secondary" title="Exchange rates">
                <i class="bi bi-currency-exchange"></i>
            </a>
            <noscript><button class="btn btn-sm btn-outline-secondary">Apply</button></noscript>
        </div>
    </form>

    {{-- Totals --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm text-center h-100"><div class="card-body py-3">
                <div class="display-6 fw-bold text-primary">{{ $departmentCount }}</div>
                <div class="small text-muted">{{ \Illuminate\Support\Str::plural($unit) }}</div>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            @include('admin.itam.reports._combined-total', ['label' => 'Per year', 'combined' => $combinedYearly])
        </div>
        <div class="col-6 col-md-4 col-xl">
            @include('admin.itam.reports._combined-total', ['label' => 'Monthly', 'combined' => $combinedRunRate])
        </div>
        <div class="col-6 col-md-6 col-xl">
            @include('admin.itam.reports._combined-total', ['label' => 'Due in '.$month->format('M Y'), 'combined' => $combinedDue])
        </div>
        <div class="col-12 col-md-6 col-xl">
            @include('admin.itam.reports._combined-total', ['label' => 'One-time purchases', 'combined' => $combinedOneTime])
        </div>
    </div>

    @include('admin.itam.reports._fx-notice', ['id' => 'fxDept', 'combined' => $combinedYearly])

    {{-- Departments or branches --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ $unit }}</th>
                        <th class="text-center">Seats</th>
                        <th class="text-center">People</th>
                        <th>Licenses</th>
                        <th class="text-end text-nowrap">Per year</th>
                        <th class="text-end">Monthly</th>
                        <th class="text-end text-nowrap">Due {{ $month->format('M') }}</th>
                        <th class="text-end">One-time</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($groups as $g)
                    <tr class="{{ $g['is_bucket'] ? 'table-warning' : '' }}">
                        <td class="fw-semibold">
                            {{ $g['name'] }}
                            @if($g['is_bucket'])
                            <i class="bi bi-exclamation-triangle text-warning ms-1"
                               title="Not a {{ strtolower($unit) }}: these seats are still paid for but have no owner to recharge"></i>
                            @endif
                        </td>
                        <td class="text-center">{{ $g['seats'] }}</td>
                        <td class="text-center">{{ $g['people'] ?: '—' }}</td>
                        <td class="small">
                            @foreach($g['services'] as $service)
                            <span class="badge bg-light text-dark border">{{ $service }}</span>
                            @endforeach
                        </td>
                        <td class="text-end font-monospace text-nowrap">{!! $cell($g['yearly'], $g['yearly_combined']) !!}</td>
                        <td class="text-end font-monospace text-nowrap">{!! $cell($g['run_rate'], $g['run_rate_combined']) !!}</td>
                        <td class="text-end font-monospace text-nowrap">{!! $cell($g['due'], $g['due_combined']) !!}</td>
                        <td class="text-end font-monospace text-nowrap">{!! $cell($g['one_time'], $g['one_time_combined']) !!}</td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center py-5 text-muted">
                        No licenses with a cost match these filters.
                    </td></tr>
                    @endforelse
                </tbody>
                @if($groups->isNotEmpty())
                <tfoot class="table-light">
                    <tr>
                        <th>Total</th>
                        <th class="text-center">{{ $groups->sum('seats') }}</th>
                        <th></th>
                        <th></th>
                        <th class="text-end font-monospace text-nowrap">{!! $cell($yearlyByCurrency, $combinedYearly) !!}</th>
                        <th class="text-end font-monospace text-nowrap">{!! $cell($runRateByCurrency, $combinedRunRate) !!}</th>
                        <th class="text-end font-monospace text-nowrap">{!! $cell($dueByCurrency, $combinedDue) !!}</th>
                        <th class="text-end font-monospace text-nowrap">{!! $cell($oneTimeByCurrency, $combinedOneTime) !!}</th>
                    </tr>
                </tfoot>
                @endif
            </table>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, .form-select, nav, .sidebar { display: none !important; }
    .card { break-inside: avoid; }
}
</style>
@endsection
