@extends('layouts.admin')
@section('title', 'Subscription Cost by Department')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Subscription Cost by Department — {{ $month->format('F Y') }}</h4>
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
        <h4 class="mb-1">Subscription Cost by Department — {{ $month->format('F Y') }}</h4>
        <div class="small text-muted">Samir Group IT · generated {{ now()->format('d M Y H:i') }}</div>
    </div>

    <p class="text-muted small mb-3">
        Every subscription seat charged to the department of the person holding it. <strong>Monthly cost</strong> is
        the run rate — what the department costs to keep running, whether or not anything renews this month.
        <strong>Due this month</strong> is each seat's share of the licences that actually renew in
        {{ $month->format('F Y') }}.
    </p>

    <div class="alert alert-light border small py-2">
        <i class="bi bi-info-circle me-1"></i>
        <strong>The department split is an allocation, not a set of payments.</strong> A licence is one indivisible
        charge on one card — finance still pays it once, from the
        <a href="{{ route('admin.itam.reports.subscription-payments', ['month' => $month->format('Y-m')]) }}">Payments Due</a>
        report. The shares here add back up to that same total, so they can be used for departmental recharge.
    </div>

    {{-- Filters --}}
    <form method="GET" class="mb-3 d-print-none">
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <label class="small text-muted mb-0">Month</label>
            <select name="month" class="form-select form-select-sm" style="max-width:180px" onchange="this.form.submit()">
                @foreach($monthOptions as $m)
                <option value="{{ $m->format('Y-m') }}" {{ $m->format('Y-m') === $month->format('Y-m') ? 'selected' : '' }}>{{ $m->format('F Y') }}</option>
                @endforeach
            </select>
            <label class="small text-muted mb-0 ms-2">Type</label>
            <select name="type" class="form-select form-select-sm" style="max-width:180px" onchange="this.form.submit()">
                <option value="ai" {{ $type === 'ai' ? 'selected' : '' }}>AI Subscriptions</option>
                <option value="subscription" {{ $type === 'subscription' ? 'selected' : '' }}>Other Subscriptions</option>
                <option value="all" {{ $type === 'all' ? 'selected' : '' }}>All Recurring</option>
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
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center h-100"><div class="card-body py-3">
                <div class="display-6 fw-bold text-primary">{{ $departmentCount }}</div>
                <div class="small text-muted">Departments</div>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            @include('admin.itam.reports._combined-total', ['label' => 'Monthly cost', 'combined' => $combinedRunRate])
        </div>
        <div class="col-6 col-md-3">
            @include('admin.itam.reports._combined-total', ['label' => 'Due in '.$month->format('M Y'), 'combined' => $combinedDue])
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                <div class="small text-muted mb-1">As invoiced — monthly cost</div>
                <div class="font-monospace small">
                    @forelse($runRateByCurrency as $currency => $total)
                    <span class="me-2">{{ $currency }} <strong>{{ number_format($total, 2) }}</strong></span>
                    @empty
                    <span class="text-muted">—</span>
                    @endforelse
                </div>
                <div class="small text-muted mt-2 mb-1">As invoiced — due this month</div>
                <div class="font-monospace small">
                    @forelse($dueByCurrency as $currency => $total)
                    <span class="me-2">{{ $currency }} <strong>{{ number_format($total, 2) }}</strong></span>
                    @empty
                    <span class="text-muted">nothing renews this month</span>
                    @endforelse
                </div>
            </div></div>
        </div>
    </div>

    @include('admin.itam.reports._fx-notice', ['id' => 'fxDept', 'combined' => $combinedRunRate])

    {{-- Departments --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Department</th>
                        <th class="text-center">Seats</th>
                        <th class="text-center">People</th>
                        <th>Services</th>
                        <th class="text-end">Monthly Cost</th>
                        <th class="text-end">≈ {{ $display }}</th>
                        <th class="text-end">Due {{ $month->format('M') }}</th>
                        <th class="text-end">≈ {{ $display }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($groups as $g)
                    <tr class="{{ $g['is_bucket'] ? 'table-warning' : '' }}">
                        <td class="fw-semibold">
                            {{ $g['department'] }}
                            @if($g['is_bucket'])
                            <i class="bi bi-exclamation-triangle text-warning ms-1"
                               title="Not a department — these seats are still invoiced but have no owner to recharge"></i>
                            @endif
                        </td>
                        <td class="text-center">{{ $g['seats'] }}</td>
                        <td class="text-center">{{ $g['people'] ?: '—' }}</td>
                        <td class="small">
                            @foreach($g['services'] as $service)
                            <span class="badge bg-light text-dark border">{{ $service }}</span>
                            @endforeach
                        </td>
                        <td class="text-end font-monospace small">
                            @foreach($g['run_rate'] as $currency => $total)
                            <div>{{ $currency }} {{ number_format($total, 2) }}</div>
                            @endforeach
                        </td>
                        <td class="text-end font-monospace fw-semibold">
                            {{ number_format($g['run_rate_combined']['total'], 2) }}
                        </td>
                        <td class="text-end font-monospace small">
                            @forelse($g['due'] as $currency => $total)
                            <div>{{ $currency }} {{ number_format($total, 2) }}</div>
                            @empty
                            <span class="text-muted">—</span>
                            @endforelse
                        </td>
                        <td class="text-end font-monospace {{ $g['due_combined']['total'] > 0 ? 'fw-semibold' : 'text-muted' }}">
                            {{ $g['due_combined']['total'] > 0 ? number_format($g['due_combined']['total'], 2) : '—' }}
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center py-5 text-muted">
                        No recurring subscriptions of this type.
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
                        <th class="text-end font-monospace small">
                            @foreach($runRateByCurrency as $currency => $total)
                            <div>{{ $currency }} {{ number_format($total, 2) }}</div>
                            @endforeach
                        </th>
                        <th class="text-end font-monospace">{{ number_format($combinedRunRate['total'], 2) }}</th>
                        <th class="text-end font-monospace small">
                            @foreach($dueByCurrency as $currency => $total)
                            <div>{{ $currency }} {{ number_format($total, 2) }}</div>
                            @endforeach
                        </th>
                        <th class="text-end font-monospace">{{ number_format($combinedDue['total'], 2) }}</th>
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
