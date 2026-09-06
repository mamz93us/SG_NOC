@extends('layouts.admin')
@section('title', 'Subscription Payments Due')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Subscription Payments Due — {{ $month->format('F Y') }}</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.itam.reports.subscriptions', ['month' => $month->format('Y-m')]) }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-robot me-1"></i>Usage Report
            </a>
            <a href="{{ route('admin.itam.reports.subscription-payments', array_merge(request()->query(), ['csv' => 1])) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Print</button>
            <a href="{{ route('admin.itam.reports.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>

    <div class="d-none d-print-block mb-3">
        <h4 class="mb-1">Subscription Payments Due — {{ $month->format('F Y') }}</h4>
        <div class="small text-muted">Samir Group IT · generated {{ now()->format('d M Y H:i') }}</div>
    </div>

    <p class="text-muted small mb-3">
        Subscriptions whose renewal date falls inside {{ $month->format('F Y') }}, at the full amount charged on that
        date. Card renewals charge themselves; everything else is a payment somebody has to raise.
    </p>

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
                <option value="all" {{ $type === 'all' ? 'selected' : '' }}>All Recurring</option>
                <option value="ai" {{ $type === 'ai' ? 'selected' : '' }}>AI Subscriptions</option>
                <option value="subscription" {{ $type === 'subscription' ? 'selected' : '' }}>Other Subscriptions</option>
            </select>
            <noscript><button class="btn btn-sm btn-outline-secondary">Apply</button></noscript>
        </div>
    </form>

    {{-- Totals --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center"><div class="card-body py-3">
                <div class="display-6 fw-bold text-primary">{{ $due->count() }}</div>
                <div class="small text-muted">Charges This Month</div>
            </div></div>
        </div>
        @forelse($totalByCurrency as $currency => $total)
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center"><div class="card-body py-3">
                <div class="h3 fw-bold text-dark mb-0">{{ $currency }} {{ number_format($total, 2) }}</div>
                <div class="small text-muted">Total due ({{ $currency }})</div>
                @if(isset($actionByCurrency[$currency]))
                <div class="small text-warning mt-1">{{ number_format($actionByCurrency[$currency], 2) }} needs action</div>
                @endif
            </div></div>
        </div>
        @empty
        <div class="col-md-6">
            <div class="card border-0 shadow-sm"><div class="card-body py-4 text-center text-muted">
                Nothing renews in {{ $month->format('F Y') }}.
            </div></div>
        </div>
        @endforelse
    </div>

    {{-- Blockers --}}
    @if($missingMethod->isNotEmpty())
    <div class="alert alert-danger py-2 small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <strong>{{ $missingMethod->count() }} charge{{ $missingMethod->count() === 1 ? '' : 's' }} with no payment
        method set</strong> — finance cannot action {{ $missingMethod->count() === 1 ? 'it' : 'them' }} without knowing
        whether it is a card or a transfer:
        {{ $missingMethod->pluck('service')->implode(', ') }}.
        <a href="{{ route('admin.itam.licenses.index') }}" class="alert-link">Set it on the licence</a>.
    </div>
    @endif

    @if($unscheduled->isNotEmpty())
    <div class="alert alert-warning py-2 small d-print-none">
        <i class="bi bi-calendar-x me-1"></i>
        <strong>{{ $unscheduled->count() }} recurring licence{{ $unscheduled->count() === 1 ? '' : 's' }} with no
        renewal date</strong> — {{ $unscheduled->count() === 1 ? 'it' : 'they' }} cannot appear in any month until a
        renewal date is set: {{ $unscheduled->pluck('license_name')->implode(', ') }}.
    </div>
    @endif

    @if(count($totalByCurrency) > 1)
    <div class="alert alert-light border small py-2">
        <i class="bi bi-info-circle me-1"></i>Amounts are shown in the currency each vendor invoices. The NOC applies
        no exchange rate — convert at the rate on the payment date.
    </div>
    @endif

    {{-- Grouped by how it is paid --}}
    @foreach($groups as $group)
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold">
                @if($group['key'] === '__unset')
                    <i class="bi bi-exclamation-circle text-danger me-1"></i>
                @elseif($group['auto_charged'])
                    <i class="bi bi-credit-card text-success me-1"></i>
                @else
                    <i class="bi bi-bank text-warning me-1"></i>
                @endif
                {{ $group['label'] }}
                <span class="badge bg-{{ $group['key'] === '__unset' ? 'danger' : ($group['auto_charged'] ? 'success' : 'warning text-dark') }} ms-1">
                    {{ $group['auto_charged'] ? 'auto-charged' : 'action needed' }}
                </span>
            </span>
            <span class="font-monospace small">
                @foreach($group['by_currency'] as $currency => $total)
                <span class="ms-2">{{ $currency }} <strong>{{ number_format($total, 2) }}</strong></span>
                @endforeach
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Due</th>
                        <th>Service</th>
                        <th>Vendor</th>
                        <th>Cycle</th>
                        <th class="text-center">Seats</th>
                        <th class="text-end">Cost / Seat</th>
                        <th class="text-end">Amount Due</th>
                        <th>Paid From</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($group['rows'] as $r)
                    <tr>
                        <td class="small">{{ $r['due_date']->format('d M Y') }}</td>
                        <td class="fw-semibold">
                            {{ $r['service'] }}
                            <span class="badge bg-light text-dark border ms-1">{{ $r['type'] }}</span>
                        </td>
                        <td class="small">{{ $r['vendor'] }}</td>
                        <td class="small">{{ $r['billing_cycle'] }}</td>
                        <td class="text-center">{{ $r['seats'] }}</td>
                        <td class="text-end font-monospace small">
                            {{ $r['cost_per_seat'] !== null ? number_format($r['cost_per_seat'], 2) : '—' }}
                        </td>
                        <td class="text-end font-monospace fw-semibold">{{ $r['currency'] }} {{ number_format($r['amount'], 2) }}</td>
                        <td class="small text-muted">{{ $r['payment_account'] ?: '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
    @endforeach
</div>

<style>
@media print {
    .btn, .form-select, nav, .sidebar { display: none !important; }
    .card { break-inside: avoid; }
}
</style>
@endsection
