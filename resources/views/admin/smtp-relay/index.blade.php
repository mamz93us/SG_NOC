@extends('layouts.admin')

@section('content')
@php
    $statusBadge = function ($status) {
        $map = [
            'sent'     => ['bg-success-subtle text-success', 'bi-check-circle', 'Sent'],
            'queued'   => ['bg-secondary-subtle text-secondary', 'bi-hourglass-split', 'Queued'],
            'deferred' => ['bg-warning-subtle text-warning', 'bi-arrow-repeat', 'Deferred'],
            'bounced'  => ['bg-danger-subtle text-danger', 'bi-x-octagon', 'Bounced'],
            'rejected' => ['bg-danger-subtle text-danger', 'bi-shield-x', 'Rejected'],
        ];
        [$cls, $icon, $label] = $map[$status] ?? ['bg-light text-muted', 'bi-question', ucfirst($status)];
        return '<span class="badge '.$cls.'"><i class="bi '.$icon.' me-1"></i>'.$label.'</span>';
    };
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-envelope-paper me-2"></i>SMTP Relay Log</h4>
    <a href="{{ route('admin.smtp-relay.export', request()->query()) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-download me-1"></i>Export CSV
    </a>
</div>

<p class="text-muted small mb-4">
    Every message the NOC relayed to Amazon SES for the legacy Ricoh scanners.
    Senders are rewritten to <code>{{ $rewrittenSender }}</code> on the way out (what AWS sees).
    <span class="text-muted">“Sent” = AWS accepted the message.</span>
</p>

{{-- KPI cards --}}
<div class="row g-3 mb-4">
    @foreach (['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days'] as $k => $label)
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">{{ $label }}</div>
                    <div class="d-flex align-items-baseline gap-3">
                        <span class="h3 fw-bold mb-0">{{ number_format($cards[$k]['count']) }}</span>
                        <span class="text-success small"><i class="bi bi-check-circle me-1"></i>{{ number_format($cards[$k]['sent']) }} sent</span>
                        @if ($cards[$k]['failed'] > 0)
                            <span class="text-danger small"><i class="bi bi-x-octagon me-1"></i>{{ number_format($cards[$k]['failed']) }} failed</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- Filter + table --}}
<div class="card shadow-sm">
    <div class="card-header bg-transparent">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-0 text-muted">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="{{ $filters['from'] }}">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-0 text-muted">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="{{ $filters['to'] }}">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-0 text-muted">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col">
                <label class="form-label small mb-0 text-muted">Search (from / to / subject / IP / SES id)</label>
                <input type="text" name="q" class="form-control form-control-sm" value="{{ $filters['q'] }}" placeholder="e.g. sssegypt.com">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-secondary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="{{ route('admin.smtp-relay.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Time</th>
                    <th>Client</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Subject</th>
                    <th class="text-end">Size</th>
                    <th>Attachments</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($messages as $m)
                    <tr>
                        <td class="text-nowrap small">{{ $m->queued_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="text-nowrap small font-monospace">{{ $m->client_ip ?? '—' }}</td>
                        <td class="small">{{ $m->mail_from ?? '—' }}</td>
                        <td class="small">{{ $m->recipients ?? '—' }}</td>
                        <td class="small">{{ $m->subject ?? '—' }}</td>
                        <td class="text-end text-nowrap small">{{ $m->humanSize() }}</td>
                        <td class="small">
                            @if ($m->attachments->isEmpty())
                                <span class="text-muted">—</span>
                            @else
                                <span class="badge bg-info-subtle text-info" title="{{ $m->attachments->pluck('filename')->implode(', ') }}">
                                    <i class="bi bi-paperclip me-1"></i>{{ $m->attachments->count() }}
                                </span>
                                @foreach ($m->attachments->take(3) as $att)
                                    <span class="d-inline-block text-truncate align-bottom" style="max-width: 180px;">
                                        {{ $att->filename }}@if($att->size_bytes) <span class="text-muted">({{ $att->humanSize() }})</span>@endif
                                    </span>@if(!$loop->last), @endif
                                @endforeach
                                @if ($m->attachments->count() > 3)
                                    <span class="text-muted">+{{ $m->attachments->count() - 3 }}</span>
                                @endif
                            @endif
                        </td>
                        <td>
                            {!! $statusBadge($m->status) !!}
                            @if ($m->error)
                                <i class="bi bi-info-circle text-muted ms-1" title="{{ $m->error }}"></i>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            No relayed messages match the filters.
                            <div class="small mt-1">Rows appear once <code>smtp-relay:ingest-log</code> has parsed the maillog.</div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card-footer bg-transparent">
        {{ $messages->links() }}
    </div>
</div>
@endsection
