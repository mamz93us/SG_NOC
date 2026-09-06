@extends('layouts.admin')
@section('title', 'AI Subscription Usage')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-robot me-2"></i>AI Subscription Usage — {{ $month->format('F Y') }}</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.itam.reports.subscription-payments', ['month' => $month->format('Y-m')]) }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-cash-coin me-1"></i>Payments Due
            </a>
            <a href="{{ route('admin.itam.reports.subscriptions-by-department', ['month' => $month->format('Y-m'), 'display' => $display]) }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-diagram-3 me-1"></i>By Department
            </a>
            <a href="{{ route('admin.itam.reports.subscriptions', array_merge(request()->query(), ['csv' => 1])) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Print</button>
            <a href="{{ route('admin.itam.reports.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>

    {{-- Print-only header, so a printed/PDF'd copy sent to finance carries its own context. --}}
    <div class="d-none d-print-block mb-3">
        <h4 class="mb-1">AI Subscription Usage — {{ $month->format('F Y') }}</h4>
        <div class="small text-muted">Samir Group IT · generated {{ now()->format('d M Y H:i') }}</div>
    </div>

    <p class="text-muted small mb-3">
        Every seat on a recurring subscription and what it costs <strong>per month</strong>. Annual and quarterly
        licences are divided down to a monthly figure, so this is a run rate for budgeting — <strong>not</strong> an
        amount to pay. For what is actually charged this month, use
        <a href="{{ route('admin.itam.reports.subscription-payments', ['month' => $month->format('Y-m')]) }}">Payments Due</a>.
    </p>

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

    {{-- Summary --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center"><div class="card-body py-3">
                <div class="display-6 fw-bold text-primary">{{ $summary['services'] }}</div>
                <div class="small text-muted">Services</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center"><div class="card-body py-3">
                <div class="display-6 fw-bold text-success">{{ $summary['assigned_seats'] }}</div>
                <div class="small text-muted">Seats in Use</div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center"><div class="card-body py-3">
                <div class="display-6 fw-bold {{ $summary['unassigned_seats'] > 0 ? 'text-warning' : 'text-muted' }}">{{ $summary['unassigned_seats'] }}</div>
                <div class="small text-muted">Idle Seats</div>
            </div></div>
        </div>
        @foreach($runRateByCurrency as $currency => $total)
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center h-100"><div class="card-body py-3">
                <div class="h4 fw-bold text-dark mb-0">{{ $currency }} {{ number_format($total, 2) }}</div>
                <div class="small text-muted">Run rate ({{ $currency }})</div>
            </div></div>
        </div>
        @endforeach
        <div class="col-12 col-md-4">
            @include('admin.itam.reports._combined-total', ['label' => 'Monthly run rate'])
        </div>
    </div>

    @include('admin.itam.reports._fx-notice', ['id' => 'fxUsage'])


    {{-- Seat detail --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-person-badge me-1"></i>Seat Detail</div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Service</th>
                        <th>Vendor</th>
                        <th>User</th>
                        <th>Email</th>
                        <th class="text-end">Cost / Seat</th>
                        <th>Cycle</th>
                        <th class="text-end">Per Month</th>
                        <th>Renews</th>
                        <th>Paid By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                    <tr class="{{ $r['seat_status'] === 'Unassigned' ? 'table-warning' : '' }}">
                        <td class="fw-semibold">{{ $r['service'] }}</td>
                        <td class="small">{{ $r['vendor'] }}</td>
                        <td>
                            {{ $r['user'] }}
                            @if($r['seat_status'] === 'Unassigned')
                            <span class="badge bg-warning text-dark ms-1">idle</span>
                            @endif
                        </td>
                        <td class="small text-muted">{{ $r['email'] ?: '—' }}</td>
                        <td class="text-end font-monospace">
                            {{ $r['cost_per_seat'] !== null ? $r['currency'].' '.number_format($r['cost_per_seat'], 2) : '—' }}
                        </td>
                        <td class="small">{{ $r['billing_cycle'] }}</td>
                        <td class="text-end font-monospace">{{ $r['currency'] }} {{ number_format($r['monthly_per_seat'], 2) }}</td>
                        <td class="small">
                            @if($r['renewal_date'])
                                {{ $r['renewal_date']->format('d M Y') }}
                            @else
                                <span class="text-muted">not this month</span>
                            @endif
                        </td>
                        <td class="small">
                            @if($r['payment_method'])
                                {{ $r['payment_method'] }}
                                @if($r['payment_account'])<div class="text-muted">{{ $r['payment_account'] }}</div>@endif
                            @else
                                <span class="badge bg-danger">not set</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="text-center py-5 text-muted">
                        No recurring subscriptions of this type. Add one from
                        <a href="{{ route('admin.itam.licenses.index') }}">Software Licenses</a>, set its billing cycle to Monthly.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>

    {{-- Per-employee roll-up --}}
    @if($byUser->isNotEmpty())
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-people me-1"></i>Monthly Cost per User</div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Services</th>
                        <th class="text-end">Monthly Cost</th>
                        <th class="text-end">≈ {{ $display }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($byUser as $u)
                    <tr>
                        <td class="fw-semibold">{{ $u['user'] }}</td>
                        <td class="small text-muted">{{ $u['email'] ?: '—' }}</td>
                        <td class="small">
                            @foreach(array_count_values($u['services']) as $service => $count)
                            <span class="badge bg-light text-dark border">{{ $service }}@if($count > 1) ×{{ $count }}@endif</span>
                            @endforeach
                        </td>
                        <td class="text-end font-monospace">
                            @foreach($u['by_currency'] as $currency => $total)
                            <div>{{ $currency }} {{ number_format($total, 2) }}</div>
                            @endforeach
                        </td>
                        @php $userTotal = $converter->totalIn($u['by_currency'], $display); @endphp
                        <td class="text-end font-monospace {{ $userTotal['all_reviewed'] ? '' : 'text-muted' }}">
                            @if($userTotal['missing'])
                                <span class="text-danger" title="No rate for {{ implode(', ', $userTotal['missing']) }}">partial</span>
                            @endif
                            {{ number_format($userTotal['total'], 2) }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
    @endif
</div>

<style>
@media print {
    .btn, .form-select, nav, .sidebar { display: none !important; }
    .card { break-inside: avoid; }
}
</style>
@endsection
