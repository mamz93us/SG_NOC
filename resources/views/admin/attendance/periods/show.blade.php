@extends('layouts.admin')

@section('title', 'Attendance — '.$period->name)

@section('content')
@include('admin.attendance._tabs')

@php
    $badge = match ($period->status) {
        'approved' => 'bg-primary',
        'sent' => 'bg-success',
        default => 'bg-secondary',
    };
    $gridQuery = array_filter([
        'from' => $period->date_from->toDateString(),
        'to' => $period->date_to->toDateString(),
        'branch' => $period->branch_id,
    ]);
@endphp

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <a href="{{ route('admin.attendance.periods.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>All periods</a>
        <h4 class="mb-0 mt-1 fw-bold">
            {{ $period->name }}
            <span class="badge {{ $badge }} fs-6 align-middle ms-1">
                @if ($period->isLocked())
                    <i class="bi bi-lock-fill me-1"></i>
                @endif
                {{ $period->statusLabel() }}
            </span>
        </h4>
        <small class="text-muted">
            {{ $period->date_from->format('d M Y') }} – {{ $period->date_to->format('d M Y') }} · {{ $period->scopeLabel() }}
            · <a href="{{ route('admin.attendance.days.index', $gridQuery) }}">see its days</a>
        </small>
    </div>
    @can('manage-attendance')
        @if ($period->status === 'open' && $exports->isEmpty())
            <form method="POST" action="{{ route('admin.attendance.periods.destroy', $period) }}" onsubmit="return confirm('Delete this period?')">
                @csrf
                @method('DELETE')
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button>
            </form>
        @endif
    @endcan
</div>

@if ($readiness)
    {{-- ── Open: is it ready? ───────────────────────────────────── --}}
    <div class="row g-3 mb-3">
        @foreach ([['People', $readiness['people'], 'text-body'], ['Person-days', $readiness['days'], 'text-body'], ['Days with data errors', $readiness['errors'], $readiness['errors'] ? 'text-danger' : 'text-success']] as [$label, $value, $cls])
            <div class="col-6 col-md-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body py-3">
                        <div class="fs-4 fw-bold {{ $cls }}">{{ number_format($value) }}</div>
                        <div class="small text-muted">{{ $label }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-transparent fw-semibold">Ready to approve?</div>
        <div class="card-body">
            @if ($readiness['blockers'])
                <ul class="text-danger mb-3">
                    @foreach ($readiness['blockers'] as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            @else
                <div class="text-success mb-3"><i class="bi bi-check-circle-fill me-1"></i>Everything checks out — this period can be approved.</div>
            @endif

            @if ($readiness['by_flag'])
                <div class="small mb-3">
                    <div class="fw-semibold mb-1">Data errors</div>
                    @foreach ($readiness['by_flag'] as $flag => $count)
                        <span class="badge bg-danger me-1">{{ $labels[$flag] ?? $flag }}: {{ $count }}</span>
                    @endforeach
                    <a href="{{ route('admin.attendance.days.index', $gridQuery + ['status' => 'error']) }}" class="ms-2">Show these days</a>
                </div>
            @endif

            @if ($readiness['missing_oracle']->isNotEmpty())
                <div class="small mb-3">
                    <div class="fw-semibold mb-1">No Oracle number</div>
                    {{ $readiness['missing_oracle']->take(25)->pluck('name')->implode(', ') }}
                    @if ($readiness['missing_oracle']->count() > 25)
                        and {{ $readiness['missing_oracle']->count() - 25 }} more
                    @endif
                </div>
            @endif

            @can('approve-attendance')
                <form method="POST" action="{{ route('admin.attendance.periods.approve', $period) }}"
                      onsubmit="return confirm('Approve and lock {{ $readiness['days'] }} day(s)? They will no longer change until the period is reopened.')">
                    @csrf
                    <button class="btn btn-primary" @disabled($readiness['blockers'])>
                        <i class="bi bi-lock me-1"></i>Approve &amp; lock
                    </button>
                </form>
            @endcan
        </div>
    </div>
@else
    {{-- ── Approved / sent: locked ──────────────────────────────── --}}
    <div class="alert alert-primary d-flex gap-2">
        <i class="bi bi-lock-fill fs-5"></i>
        <div>
            Approved by <strong>{{ $period->approvedBy?->name ?? 'unknown' }}</strong> on {{ $period->approved_at?->format('d M Y H:i') }}.
            {{ number_format($lockedDays) }} day(s) are locked — punches, shift changes and corrections no longer change them.
            @if ($period->sent_at)
                Sent to Oracle on {{ $period->sent_at->format('d M Y H:i') }}.
            @endif
        </div>
    </div>

    @if ($lateArrivals > 0)
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            {{ number_format($lateArrivals) }} punch(es) for people in this period arrived after it was approved. They are stored,
            but not in the approved figures — reopen the period to include them.
        </div>
    @endif

    @can('approve-attendance')
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body d-flex flex-wrap gap-3 align-items-end">
                <form method="POST" action="{{ route('admin.attendance.periods.export', $period) }}">
                    @csrf
                    <button class="btn btn-outline-primary" @disabled($exportQueued)>
                        <i class="bi bi-send me-1"></i>{{ $exportQueued ? 'Export being prepared…' : 'Prepare the Oracle export again' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.attendance.periods.reopen', $period) }}" class="d-flex gap-2 flex-grow-1"
                      onsubmit="return confirm('Reopen this period? Its days unlock and are recalculated.')">
                    @csrf
                    <input name="reason" class="form-control" required maxlength="1000" placeholder="Why reopen — e.g. late uploads, a correction">
                    <button class="btn btn-outline-danger text-nowrap"><i class="bi bi-unlock me-1"></i>Reopen</button>
                </form>
            </div>
        </div>
    @endcan
@endif

@if ($period->reopened_at)
    <p class="small text-muted">
        Last reopened by {{ $period->reopenedBy?->name ?? 'unknown' }} on {{ $period->reopened_at->format('d M Y H:i') }}: {{ $period->reopen_reason }}
    </p>
@endif

{{-- ── Exports ──────────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent fw-semibold">Oracle exports</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Prepared</th>
                    <th>Status</th>
                    <th class="text-end">Records</th>
                    <th>Message</th>
                    <th style="width:170px">Download</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($exports as $export)
                    <tr>
                        <td class="small text-nowrap">
                            {{ $export->created_at?->format('d M Y H:i') }}
                            <div class="text-muted">{{ $export->createdBy?->name ?? 'system' }} · {{ $export->sender }}</div>
                        </td>
                        <td>
                            <span class="badge {{ $export->status === 'sent' ? 'bg-success' : ($export->status === 'failed' ? 'bg-danger' : 'bg-secondary') }}">
                                {{ ucfirst($export->status) }}
                            </span>
                            @if ($export->reference)
                                <div class="small text-muted font-monospace">{{ $export->reference }}</div>
                            @endif
                        </td>
                        <td class="text-end">{{ number_format($export->record_count) }}</td>
                        <td class="small">{{ $export->message }}</td>
                        <td>
                            <a href="{{ route('admin.attendance.exports.download', [$export, 'csv']) }}" class="btn btn-sm btn-outline-secondary">CSV</a>
                            <a href="{{ route('admin.attendance.exports.download', [$export, 'json']) }}" class="btn btn-sm btn-outline-secondary">JSON</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            @if ($exportQueued)
                                The export is being prepared — refresh in a minute.
                            @else
                                Nothing exported yet. Approving the period prepares it.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
