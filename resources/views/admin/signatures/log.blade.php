@extends('layouts.admin')

@section('content')

<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Signature Request Log</h1>
        <small class="text-muted">Every call to the signature API — who requested a signature and what NOC served back</small>
    </div>
    <div class="ms-auto">
        <a href="{{ route('admin.signatures.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Templates
        </a>
    </div>
</div>

{{-- ── Stat cards ── --}}
<div class="row g-3 mb-4">
    @php
        $cards = [
            ['label' => 'Total requests', 'value' => number_format($stats['total']),   'icon' => 'bi-envelope',        'cls' => 'text-primary'],
            ['label' => 'Today',          'value' => number_format($stats['today']),   'icon' => 'bi-calendar-day',    'cls' => 'text-info'],
            ['label' => 'Served OK',      'value' => number_format($stats['ok']),      'icon' => 'bi-check-circle',    'cls' => 'text-success'],
            ['label' => 'People served',  'value' => number_format($stats['people']),  'icon' => 'bi-people',          'cls' => 'text-dark'],
            ['label' => 'Not found',      'value' => number_format($stats['missing']), 'icon' => 'bi-exclamation-triangle', 'cls' => 'text-warning'],
        ];
    @endphp
    @foreach($cards as $c)
    <div class="col">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-2 text-muted small mb-1">
                    <i class="bi {{ $c['icon'] }} {{ $c['cls'] }}"></i>{{ $c['label'] }}
                </div>
                <div class="h4 fw-bold mb-0">{{ $c['value'] }}</div>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- ── Filters ── --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" value="{{ $q }}" class="form-control form-control-sm"
                       placeholder="UPN / mailbox, name, or template…">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Any status</option>
                    @foreach(['ok' => 'Served OK', 'not_found' => 'Not found', 'unauthorized' => 'Unauthorized', 'bad_request' => 'Bad request', 'error' => 'Error'] as $val => $lbl)
                        <option value="{{ $val }}" @selected($status === $val)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Domain</label>
                <select name="domain" class="form-select form-select-sm">
                    <option value="">Any domain</option>
                    @foreach($domains as $d)
                        <option value="{{ $d }}" @selected($domain === $d)>{{ $d }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="{{ route('admin.signatures.log') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

{{-- ── Log table ── --}}
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        @if($logs->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-clock-history fs-1 d-block mb-2"></i>
                No signature requests logged yet.
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                <thead class="table-light">
                    <tr>
                        <th>When</th>
                        <th>Mailbox / UPN</th>
                        <th>Served name</th>
                        <th>Domain</th>
                        <th>Type</th>
                        <th>Template</th>
                        <th>Status</th>
                        <th>Source IP</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($logs as $log)
                    <tr>
                        <td class="text-muted text-nowrap" title="{{ $log->created_at }}">
                            {{ optional($log->created_at)->format('Y-m-d H:i') }}
                        </td>
                        <td class="fw-semibold text-break">{{ $log->upn ?? '—' }}</td>
                        <td>{{ $log->resolved_name ?? '—' }}</td>
                        <td>
                            @if($log->domain)
                                <span class="badge bg-light text-dark border font-monospace">{{ $log->domain }}</span>
                            @else — @endif
                        </td>
                        <td>
                            @php $ep = $log->endpoint; @endphp
                            @if($ep === 'render')
                                <span class="badge bg-light text-dark border">{{ $log->type === 'reply' ? 'Reply' : 'New mail' }}</span>
                            @elseif($ep === 'transport_rule')
                                <span class="badge bg-info-subtle text-info-emphasis border">Transport rule{{ $log->gender ? ' · '.$log->gender : '' }}</span>
                            @elseif($ep === 'gender_members')
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border">Group members{{ $log->gender ? ' · '.$log->gender : '' }}</span>
                            @else
                                <span class="badge bg-light text-dark border">{{ $ep }}</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $log->template_name ?? '—' }}</td>
                        <td>
                            @php
                                $map = [
                                    'ok'           => ['success', 'Served'],
                                    'not_found'    => ['warning', 'Not found'],
                                    'unauthorized' => ['danger',  'Unauthorized'],
                                    'bad_request'  => ['secondary', 'Bad request'],
                                    'error'        => ['danger',  'Error'],
                                ];
                                [$cls, $lbl] = $map[$log->status] ?? ['secondary', $log->status];
                            @endphp
                            <span class="badge bg-{{ $cls }}-subtle text-{{ $cls }}-emphasis border">{{ $lbl }}</span>
                        </td>
                        <td class="text-muted font-monospace small">{{ $log->ip ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @if($logs->hasPages())
    <div class="card-footer bg-transparent border-0">
        {{ $logs->links() }}
    </div>
    @endif
</div>

@endsection
