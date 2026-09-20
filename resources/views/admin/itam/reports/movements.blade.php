@extends('layouts.admin')

@section('title', 'Asset Movements')

@section('content')
@php
    $query = array_filter($filters, fn ($value) => $value !== null && $value !== '');
@endphp

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Asset Movements</h4>
        <small class="text-muted">
            Every transfer, retirement and scrap in the period, with the Oracle asset number beside each one — the list
            to hand the finance team so they can post it against Oracle's register.
        </small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.itam.reports.movements', $query + ['print' => 1]) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>Print
        </a>
        <a href="{{ route('admin.itam.reports.movements', $query + ['csv' => 1]) }}" class="btn btn-sm btn-outline-success">
            <i class="bi bi-filetype-csv me-1"></i>CSV
        </a>
    </div>
</div>

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label small mb-1">From</label>
        <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control form-control-sm">
    </div>
    <div class="col-auto">
        <label class="form-label small mb-1">To</label>
        <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control form-control-sm">
    </div>
    <div class="col-auto">
        <label class="form-label small mb-1">Movement</label>
        <select name="kind" class="form-select form-select-sm">
            <option value="">All movements</option>
            @foreach ($kinds as $key => $label)
                <option value="{{ $key }}" @selected($filters['kind'] === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-1">Branch</label>
        <select name="branch" class="form-select form-select-sm">
            <option value="">All branches</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected($filters['branch'] === $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-1">Employee</label>
        <select name="employee" class="form-select form-select-sm" style="max-width:220px">
            <option value="">Anyone</option>
            @foreach ($employees as $employee)
                <option value="{{ $employee->id }}" @selected($filters['employee'] === $employee->id)>{{ $employee->name }}@if($employee->oracle_emp_no) — {{ $employee->oracle_emp_no }}@endif</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-1">Oracle no.</label>
        <input type="search" name="oracle" value="{{ $filters['oracle'] }}" class="form-control form-control-sm" style="max-width:150px" placeholder="e.g. 1001946">
    </div>
    <div class="col-auto">
        <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Show</button>
        <a href="{{ route('admin.itam.reports.movements') }}" class="btn btn-sm btn-link">This month</a>
    </div>
</form>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100"><div class="card-body py-2">
            <div class="text-muted small">Movements</div>
            <div class="fs-4 fw-bold">{{ number_format($summary['count']) }}</div>
        </div></div>
    </div>
    @foreach ($kinds as $key => $label)
        <div class="col-6 col-md-2">
            <div class="card shadow-sm border-0 h-100"><div class="card-body py-2">
                <div class="text-muted small">{{ $label }}</div>
                <div class="fs-4 fw-bold">{{ number_format($summary['kinds'][$key] ?? 0) }}</div>
            </div></div>
        </div>
    @endforeach
    @if ($summary['cost'] !== [])
        <div class="col-12 col-md-3">
            <div class="card shadow-sm border-0 h-100"><div class="card-body py-2">
                <div class="text-muted small" title="Only assets that left the books, and only where a cost is recorded">Retired and scrapped, at cost</div>
                @foreach ($summary['cost'] as $currency => $total)
                    <div class="fw-bold">{{ $currency }} {{ number_format($total, 2) }}</div>
                @endforeach
            </div></div>
        </div>
    @endif
</div>

@if ($summary['reasons'] !== [])
    <div class="mb-3 small">
        <span class="text-muted me-1">Reasons:</span>
        @foreach ($summary['reasons'] as $reason => $count)
            <span class="badge bg-light text-dark border me-1">{{ $reason }} · {{ $count }}</span>
        @endforeach
    </div>
@endif

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Date</th>
                    <th>Movement</th>
                    <th>Asset code</th>
                    <th>Asset</th>
                    <th>Oracle no.</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Reason</th>
                    <th>Cost</th>
                    <th class="pe-3">Recorded by</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="ps-3 text-nowrap">
                            {{ $row['date']?->format('d M Y') }}
                            @if ($row['recorded_at'] && $row['date'] && ! $row['recorded_at']->isSameDay($row['date']))
                                <div class="text-muted" title="When it was recorded in the NOC">recorded {{ $row['recorded_at']->format('d M') }}</div>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            <span class="badge {{ ['transfer' => 'bg-primary', 'return' => 'bg-success', 'store' => 'bg-info text-dark', 'retire' => 'bg-secondary', 'scrap' => 'bg-danger'][$row['kind']] ?? 'bg-light text-dark' }}">{{ $row['kind_label'] }}</span>
                            @if ($row['workflow_id'])
                                <a href="{{ route('admin.itam.scrap.show', $row['workflow_id']) }}" class="d-block text-muted">request #{{ $row['workflow_id'] }}</a>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            @if ($row['device_id'])
                                <a href="{{ route('admin.devices.show', $row['device_id']) }}" class="text-decoration-none font-monospace fw-semibold">{{ $row['asset_code'] ?: '—' }}</a>
                            @else
                                <span class="font-monospace text-muted">{{ $row['asset_code'] ?: '—' }}</span>
                            @endif
                        </td>
                        <td>
                            <div class="text-truncate" style="max-width:260px" title="{{ $row['name'] }}">{{ $row['name'] }}</div>
                            @if ($row['serial_number'])
                                <div class="text-muted small font-monospace">{{ $row['serial_number'] }}</div>
                            @endif
                        </td>
                        <td class="font-monospace text-nowrap">{{ $row['oracle_asset_number'] ?: '—' }}</td>
                        <td>
                            {{ $row['from'] ?: '—' }}
                            @if ($row['from_no'])<div class="text-muted small">Emp #{{ $row['from_no'] }}</div>@endif
                        </td>
                        <td>
                            {{ $row['to'] ?: ($row['storage_location'] ?: '—') }}
                            @if ($row['to_no'])<div class="text-muted small">Emp #{{ $row['to_no'] }}</div>@endif
                        </td>
                        <td>
                            {{ $row['reason_label'] ?: '—' }}
                            @if ($row['reason'] && $row['reason'] !== $row['reason_label'])
                                <div class="text-muted text-truncate" style="max-width:240px" title="{{ $row['reason'] }}">{{ $row['reason'] }}</div>
                            @endif
                            @if ($row['disposal_method'])
                                <span class="badge bg-light text-dark border">{{ ucfirst(str_replace('_', ' ', $row['disposal_method'])) }}</span>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $row['purchase_cost'] !== null ? $row['currency'].' '.number_format($row['purchase_cost'], 2) : '—' }}</td>
                        <td class="pe-3 text-muted">{{ $row['by'] ?: 'system' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-5">
                            <i class="bi bi-arrow-left-right d-block display-5 mb-2 opacity-25"></i>
                            No asset moved between {{ \Carbon\Carbon::parse($filters['from'])->format('d M Y') }} and {{ \Carbon\Carbon::parse($filters['to'])->format('d M Y') }}.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
