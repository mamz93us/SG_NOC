@extends('layouts.admin')

@section('title', 'Attendance — Periods')

@section('content')
@include('admin.attendance._tabs')

<div class="mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-journal-check me-2 text-primary"></i>Periods</h4>
    <small class="text-muted">
        A period is what goes to Oracle: a date range for one branch or for all of them. It can be approved only when none of its
        days has a data error, everyone in it has an Oracle number, and its last day is over. Approving <strong>locks</strong> its
        days — later punches, shift changes and corrections no longer change them — and prepares the Oracle export.
        Reopen a period to change it again.
    </small>
</div>

@can('manage-attendance')
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body py-3">
            <form method="POST" action="{{ route('admin.attendance.periods.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" required
                           value="{{ old('date_from', now()->subMonthNoOverflow()->startOfMonth()->toDateString()) }}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" required
                           value="{{ old('date_to', now()->subMonthNoOverflow()->endOfMonth()->toDateString()) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Applies to</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Name <span class="text-muted">(optional)</span></label>
                    <input name="name" class="form-control form-control-sm" maxlength="150" value="{{ old('name') }}"
                           placeholder="e.g. Aug 2026 · Jeddah">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Create period</button>
                </div>
            </form>
        </div>
    </div>
@endcan

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Period</th>
                    <th>Applies to</th>
                    <th>Dates</th>
                    <th>Status</th>
                    <th>Approved</th>
                    <th>Last export</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($periods as $period)
                    @php
                        $badge = match ($period->status) {
                            'approved' => 'bg-primary',
                            'sent' => 'bg-success',
                            default => 'bg-secondary',
                        };
                    @endphp
                    <tr style="cursor:pointer" onclick="window.location='{{ route('admin.attendance.periods.show', $period) }}'">
                        <td>
                            <a href="{{ route('admin.attendance.periods.show', $period) }}" class="fw-semibold text-decoration-none">{{ $period->name }}</a>
                        </td>
                        <td class="small">{{ $period->scopeLabel() }}</td>
                        <td class="small text-nowrap">{{ $period->date_from->format('d M') }} – {{ $period->date_to->format('d M Y') }}</td>
                        <td>
                            <span class="badge {{ $badge }}">
                                @if ($period->isLocked())
                                    <i class="bi bi-lock-fill me-1"></i>
                                @endif
                                {{ $period->statusLabel() }}
                            </span>
                        </td>
                        <td class="small">
                            @if ($period->approved_at)
                                {{ $period->approvedBy?->name ?? '—' }}, {{ $period->approved_at->format('d M H:i') }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="small">
                            @if ($period->latestExport)
                                {{ ucfirst($period->latestExport->status) }} · {{ number_format($period->latestExport->record_count) }} record(s)
                                <div class="text-muted">{{ $period->latestExport->created_at?->diffForHumans() }}</div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-journal fs-3 d-block mb-2"></i>
                            No periods yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
