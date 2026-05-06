@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-info"></i>UCM / Asterisk — Log Viewer</h4>
        <small class="text-muted">Parsed Asterisk &amp; Grandstream events</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.syslog.index', ['source_type' => 'ucm']) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list me-1"></i>Raw view
        </a>
        <a href="{{ route('admin.syslog.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>All Syslog
        </a>
        @can('manage-syslog')
        <form method="POST" action="{{ route('admin.syslog.clear') }}" class="d-inline"
              onsubmit="var v = prompt('This will DELETE every row in syslog_messages (not just UCM).\n\nType CLEAR to confirm.'); if (v === 'CLEAR') { this.confirm.value = v; return true; } return false;">
            @csrf
            <input type="hidden" name="confirm" value="">
            <button type="submit" class="btn btn-outline-danger btn-sm" title="Wipe all syslog rows">
                <i class="bi bi-trash3 me-1"></i>Clear all
            </button>
        </form>
        @endcan
    </div>
</div>

@php
    $backlog = \Illuminate\Support\Facades\Cache::remember(
        'syslog.ucm.backlog', 60,
        fn () => \App\Models\SyslogMessage::where('source_type', 'ucm')
                    ->whereNull('parsed')->count()
    );
@endphp
<div class="alert {{ $backlog > 0 ? 'alert-warning' : 'alert-success' }} py-2 mb-3 d-flex justify-content-between align-items-center">
    <div>
        @if($backlog > 0)
        <i class="bi bi-hourglass-split me-1"></i>
        <strong>{{ number_format($backlog) }}</strong> UCM rows pending parse — the scheduler drains up to 25,000/min.
        @else
        <i class="bi bi-check-circle-fill me-1"></i>
        Parser is up to date — no UCM rows pending.
        @endif
    </div>
    @can('manage-syslog')
    <form method="POST" action="{{ route('admin.syslog.run-processors') }}" class="d-inline m-0">
        @csrf
        <button type="submit" class="btn btn-sm {{ $backlog > 0 ? 'btn-primary' : 'btn-outline-primary' }}">
            <i class="bi bi-play-fill me-1"></i>Run parser now
        </button>
    </form>
    @endcan
</div>

{{-- Filters --}}
<form method="GET" class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">Host</label>
                <input type="text" name="host" value="{{ $filters['host'] }}"
                       class="form-control form-control-sm" placeholder="ucm.jed">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Asterisk severity</label>
                <select name="asterisk_severity" class="form-select form-select-sm">
                    <option value="">Any</option>
                    @foreach($severities as $s)
                    <option value="{{ $s }}" {{ $filters['asterisk_severity'] === $s ? 'selected' : '' }}>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Program</label>
                <select name="program" class="form-select form-select-sm">
                    <option value="">Any</option>
                    @foreach($programs as $p)
                    <option value="{{ $p }}" {{ $filters['program'] === $p ? 'selected' : '' }}>{{ $p }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Call ID</label>
                <input type="text" name="call_id" value="{{ $filters['call_id'] }}"
                       class="form-control form-control-sm" placeholder="0000025e">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Security only</label>
                <select name="security" class="form-select form-select-sm">
                    <option value="">No</option>
                    <option value="1" {{ $filters['security'] === '1' ? 'selected' : '' }}>Yes</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small mb-1">Since</label>
                <select name="since" class="form-select form-select-sm">
                    @foreach(['15m'=>'15m','1h'=>'1h','24h'=>'24h','7d'=>'7d','30d'=>'30d','all'=>'All'] as $v=>$l)
                    <option value="{{ $v }}" {{ $filters['since'] === $v ? 'selected' : '' }}>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-12 d-flex gap-2">
                <input type="text" name="search" value="{{ $filters['search'] }}"
                       class="form-control form-control-sm" placeholder="Search message body…">
                <button type="submit" class="btn btn-primary btn-sm flex-shrink-0"><i class="bi bi-funnel"></i> Filter</button>
                <a href="{{ route('admin.syslog.ucm') }}" class="btn btn-outline-secondary btn-sm flex-shrink-0">Reset</a>
            </div>
        </div>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($messages->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-telephone display-4 d-block mb-2"></i>
            No UCM events match these filters.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width:160px">Time</th>
                        <th style="width:90px">Severity</th>
                        <th style="width:90px">Program</th>
                        <th style="width:130px">Host</th>
                        <th style="width:100px">Call ID</th>
                        <th style="width:200px">File:Line</th>
                        <th style="width:150px">Function</th>
                        <th class="pe-3">Message</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($messages as $m)
                    @php
                        $p = $m->parsed ?? [];
                        $sev = strtoupper((string) ($p['asterisk_severity'] ?? ''));
                        $sevClass = match ($sev) {
                            'ERROR','SEVERE','FATAL' => 'bg-danger',
                            'WARNING'                => 'bg-warning text-dark',
                            'SECURITY'               => 'bg-dark',
                            'NOTICE'                 => 'bg-info text-dark',
                            'VERBOSE','DEBUG'        => 'bg-secondary',
                            default                  => 'bg-light text-dark border',
                        };
                        $rowClass = $sev === 'SECURITY' ? 'table-warning'
                                  : (in_array($sev, ['ERROR','SEVERE','FATAL']) ? 'table-danger' : '');
                        $fileLine = ($p['file'] ?? '') . (isset($p['line']) ? ':' . $p['line'] : '');
                    @endphp
                    <tr class="{{ $rowClass }}" style="cursor:pointer" onclick="window.location='{{ route('admin.syslog.show', $m->id) }}'">
                        <td class="ps-3 text-nowrap text-muted" title="{{ $m->received_at->toDateTimeString() }}">{{ $m->received_at->format('M d H:i:s') }}</td>
                        <td>
                            @if($sev)
                            <span class="badge {{ $sevClass }}">{{ $sev }}</span>
                            @if(!empty($p['security_event']))
                            <span class="badge bg-dark ms-1" title="SecurityEvent">{{ $p['security_event'] }}</span>
                            @endif
                            @else
                            <span class="badge {{ $m->severityBadgeClass() }}">{{ $m->severityLabel() }}</span>
                            @endif
                        </td>
                        <td>{{ $p['program'] ?? '—' }}</td>
                        <td class="text-truncate" style="max-width:130px" title="{{ $m->host }}">{{ $m->host }}</td>
                        <td class="font-monospace small">{{ $p['call_id'] ?? '—' }}</td>
                        <td class="font-monospace small text-truncate" style="max-width:200px" title="{{ $fileLine }}">{{ $fileLine ?: '—' }}</td>
                        <td class="text-truncate" style="max-width:150px" title="{{ $p['function'] ?? '' }}">{{ $p['function'] ?? '—' }}</td>
                        <td class="pe-3 text-truncate" style="max-width:520px">{{ $p['text'] ?? $m->message }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 border-top">{{ $messages->links() }}</div>
        @endif
    </div>
</div>
@endsection
