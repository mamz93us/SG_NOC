@extends('layouts.admin')

@section('title', 'Mail Delivery Log')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="mb-0"><i class="bi bi-send-check-fill me-2 text-primary"></i>Mail Delivery Log</h4>
            <small class="text-muted">
                Every message AWS SES sent for this account — NOC alerts, workflow mail and
                campaigns — and what happened to it. Reported by SES itself.
            </small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.mail-delivery.events') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list-ul me-1"></i>Raw events
            </a>
            @can('view-email-logs')
            <a href="{{ route('admin.email-log.index') }}" class="btn btn-sm btn-outline-secondary"
               title="What this app handed to the mailer, before SES">
                <i class="bi bi-envelope me-1"></i>App send log
            </a>
            @endcan
        </div>
    </div>

    {{-- ── Last 30 days ── --}}
    <div class="row g-2 mb-3">
        @foreach([
            ['Sent (30d)', $stats['total'],      'bi-envelope',            'text-primary', null],
            ['Delivered',  $stats['delivered'],  'bi-check-circle-fill',   'text-success', 'delivered'],
            ['Bounced',    $stats['bounced'],    'bi-x-octagon-fill',      'text-danger',  'bounced'],
            ['Complaints', $stats['complained'], 'bi-exclamation-triangle-fill', 'text-warning', 'complained'],
            ['No result yet', $stats['pending'], 'bi-hourglass',           'text-muted',   'sent'],
        ] as [$label, $value, $icon, $colour, $filter])
        <div class="col-6 col-lg">
            <a href="{{ $filter ? route('admin.mail-delivery.index', ['status' => $filter]) : route('admin.mail-delivery.index') }}"
               class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body py-2">
                        <div class="small text-muted"><i class="bi {{ $icon }} {{ $colour }} me-1"></i>{{ $label }}</div>
                        <div class="fs-4 fw-semibold">{{ number_format($value) }}</div>
                    </div>
                </div>
            </a>
        </div>
        @endforeach
    </div>

    {{-- ── Filters ── --}}
    <form method="GET" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm"
                           placeholder="recipient, sender or subject…">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach(['sent' => 'No result yet', 'delivered' => 'Delivered', 'bounced' => 'Bounced', 'complained' => 'Complaint', 'rejected' => 'Rejected', 'failed' => 'Failed'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Source</label>
                    <select name="source" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option value="transactional" @selected(request('source') === 'transactional')>Transactional</option>
                        <option value="marketing" @selected(request('source') === 'marketing')>Marketing</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" value="{{ request('from_date') }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" value="{{ request('to_date') }}" class="form-control form-control-sm">
                </div>
                <div class="col-12 d-flex gap-2 mt-2">
                    <button class="btn btn-sm btn-primary">Filter</button>
                    <a href="{{ route('admin.mail-delivery.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                    <div class="form-check form-switch ms-2 mt-1">
                        <input class="form-check-input" type="checkbox" name="opened" value="1"
                               id="openedOnly" @checked(request('opened')) onchange="this.form.submit()">
                        <label class="form-check-label small" for="openedOnly">Opened only</label>
                    </div>
                    <span class="ms-auto small text-muted align-self-center">
                        {{ number_format($messages->total()) }} message{{ $messages->total() === 1 ? '' : 's' }}
                    </span>
                </div>
            </div>
        </div>
    </form>

    {{-- ── Messages ── --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:145px;">Sent</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Subject</th>
                            <th style="width:130px;">Status</th>
                            <th style="width:90px;" class="text-center">Opens</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($messages as $message)
                        <tr>
                            <td class="small text-muted text-nowrap" title="{{ $message->sent_at }}">
                                {{ $message->sent_at?->format('d M H:i') ?? '—' }}
                            </td>
                            <td class="small">
                                <span title="{{ $message->fromLabel() }}">{{ $message->from_email ?? '—' }}</span>
                                @if($message->source === 'marketing')
                                    <span class="badge {{ $message->sourceBadgeClass() }} ms-1">campaign</span>
                                @endif
                            </td>
                            <td class="small">{{ $message->recipientLabel() ?: '—' }}</td>
                            <td>
                                <a href="{{ route('admin.mail-delivery.show', $message) }}"
                                   class="text-decoration-none d-inline-block text-truncate"
                                   style="max-width:420px" title="{{ $message->subject }}">
                                    {{ $message->subject ?: '(no subject)' }}
                                </a>
                            </td>
                            <td>
                                <span class="badge {{ $message->statusBadgeClass() }}">{{ $message->status }}</span>
                                @if($message->bounce_type)
                                    <div class="small text-muted">{{ $message->bounce_type }}</div>
                                @endif
                            </td>
                            <td class="text-center small">
                                @if($message->open_count)
                                    <span class="text-success">{{ $message->open_count }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                                @if($message->click_count)
                                    <span class="text-muted">/ {{ $message->click_count }} clicks</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                No messages match. If this is empty everywhere, run
                                <code>php artisan email-log:backfill</code> to rebuild from stored SES events.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">{{ $messages->links() }}</div>

    <p class="small text-muted mt-2 mb-0">
        <i class="bi bi-info-circle me-1"></i>
        Only messages sent with the SES configuration set appear here — SES has no way to
        list mail it sent before event publishing was switched on.
    </p>
</div>
@endsection
