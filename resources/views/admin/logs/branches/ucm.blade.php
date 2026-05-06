@extends('layouts.admin')

@section('title', 'Branch Logs — UCM / by IP')

@php
    $displayTz = request('tz') ?: config('app.timezone', 'UTC');
    $tzLabel   = (function ($tz) {
        try { $o = (new DateTime('now', new DateTimeZone($tz)))->format('P');
              return $tz . ' (UTC' . ($o === '+00:00' ? '' : $o) . ')'; }
        catch (Throwable) { return $tz; }
    })($displayTz);
    $fmtTime = function (?string $utc) use ($displayTz) {
        if (!$utc) return '';
        try { return \Illuminate\Support\Carbon::parse($utc, 'UTC')
                   ->setTimezone($displayTz)->format('Y-m-d H:i:s'); }
        catch (Throwable) { return $utc; }
    };
    $sevBadge = function (?string $s) {
        return match ((string) $s) {
            'ERROR', 'SECURITY' => 'bg-danger',
            'WARNING'           => 'bg-warning text-dark',
            'NOTICE'            => 'bg-info text-dark',
            'VERBOSE', 'DEBUG'  => 'bg-light text-muted',
            'INFO', ''          => 'bg-secondary',
            default             => 'bg-secondary',
        };
    };
@endphp

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Branch Logs — by IP (UCM-focused)</h4>
            <small class="text-muted">
                See every syslog row sent by a specific device IP — UCM, switch,
                AP, anything. Asterisk-format messages get parsed automatically
                into severity / call ID / file / function columns.
            </small>
        </div>
        @if(count($branches))
            <span class="badge bg-info text-dark">
                {{ count($branches) }} branch{{ count($branches) === 1 ? '' : 'es' }} reachable
            </span>
        @endif
    </div>

    <form method="GET" action="{{ route('admin.logs.branches.ucm') }}" class="card mb-3">
        <div class="card-body py-2">
            <input type="hidden" name="search" value="1">
            <div class="row g-2 small align-items-end">

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Source IP</label>
                    <input type="text" name="source_ip"
                           value="{{ $sourceIp }}"
                           placeholder="10.3.0.10"
                           class="form-control form-control-sm font-monospace"
                           autofocus required>
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Branches</label>
                    <select name="branches" class="form-select form-select-sm" multiple size="3">
                        @foreach($branches as $id => $info)
                            <option value="{{ $id }}"
                                @if(in_array($id, $selectedBranches)) selected @endif>
                                {{ $info['name'] }} ({{ $id }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">From</label>
                    <input type="datetime-local" name="from"
                           value="{{ $filters['from'] }}"
                           class="form-control form-control-sm">
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">To</label>
                    <input type="datetime-local" name="to"
                           value="{{ $filters['to'] }}"
                           class="form-control form-control-sm">
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Severity ≤</label>
                    <select name="severity" class="form-select form-select-sm">
                        <option value="">any</option>
                        <option value="3" @if($filters['severity']==='3') selected @endif>3 error</option>
                        <option value="4" @if($filters['severity']==='4') selected @endif>4 warning</option>
                        <option value="5" @if($filters['severity']==='5') selected @endif>5 notice</option>
                        <option value="6" @if($filters['severity']==='6') selected @endif>6 info</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Free-text</label>
                    <input type="text" name="q"
                           value="{{ $filters['q'] }}"
                           placeholder="SIPTransaction, SECURITY, …"
                           class="form-control form-control-sm">
                </div>

                @php $rowsSelected = (int) request('rows', 500); @endphp
                <div class="col-md-1 mt-2">
                    <label class="form-label small text-muted mb-1">Rows</label>
                    <select name="rows" class="form-select form-select-sm">
                        @foreach([200, 500, 1000] as $opt)
                            <option value="{{ $opt }}" @if($rowsSelected === $opt) selected @endif>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3 mt-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-search me-1"></i>Search
                    </button>
                    <a href="{{ route('admin.logs.branches.ucm') }}" class="btn btn-sm btn-outline-secondary">
                        Clear
                    </a>
                </div>
            </div>
        </div>
    </form>

    @if($results)
        <div class="card">
            <div class="card-body p-2">

                <div class="d-flex justify-content-between align-items-center mb-2 small">
                    <span class="text-muted">
                        <strong>{{ number_format($results['total']) }}</strong> total matches across
                        <strong>{{ count($results['branches']) }}</strong> branches —
                        showing first {{ count($results['results']) }} —
                        took {{ $results['took_ms'] }} ms
                    </span>

                    <div class="d-flex gap-2 align-items-center">
                        @if(!empty($results['errors']))
                            <span class="text-warning">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                {{ count($results['errors']) }} branch error(s):
                                @foreach($results['errors'] as $bid => $err)
                                    <span title="{{ $err }}">{{ $bid }}</span>@if(!$loop->last), @endif
                                @endforeach
                            </span>
                        @endif
                        <a href="?{{ http_build_query(array_merge(request()->query(), ['export' => 'csv', 'rows' => 5000])) }}"
                           class="btn btn-sm btn-outline-success"
                           title="Up to 5,000 rows per branch (Excel-friendly UTF-8)">
                            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
                        </a>
                    </div>
                </div>

                <div class="table-responsive" style="max-height: 75vh;">
                    <table class="table table-sm table-striped table-hover small mb-0 align-middle">
                        <thead class="sticky-top bg-light">
                            <tr>
                                <th style="width:170px;" title="{{ $tzLabel }}">Time</th>
                                <th style="width: 56px;">Branch</th>
                                <th style="width:110px;">Source IP</th>
                                <th style="width: 90px;">Severity</th>
                                <th style="width:140px;">Call ID</th>
                                <th style="width:200px;">File:Line · func</th>
                                <th>Message</th>
                                <th style="width: 32px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($results['results'] as $i => $r)
                            @php
                                $get = fn ($k, $d = '') => $r[$k] ?? $d;
                                $aSev = $get('a_severity') ?: null;
                                $body = $get('a_body') ?: $get('message');
                            @endphp
                            <tr>
                                <td class="text-nowrap font-monospace" title="UTC: {{ $get('received_at') }}">
                                    {{ $fmtTime($get('received_at')) }}
                                </td>
                                <td><span class="badge bg-secondary">{{ $get('branch_id') }}</span></td>
                                <td class="font-monospace small">{{ $get('source_ip') }}</td>
                                <td>
                                    @if($aSev)
                                        <span class="badge {{ $sevBadge($aSev) }}">{{ $aSev }}</span>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                <td class="font-monospace small text-muted">
                                    {{ $get('a_call_id') ? 'C-' . $get('a_call_id') : '' }}
                                </td>
                                <td class="font-monospace small text-muted text-truncate" style="max-width:200px;"
                                    title="{{ $get('a_file') }}:{{ $get('a_line') }} in {{ $get('a_func') }}">
                                    @if($get('a_file'))
                                        {{ \Illuminate\Support\Str::afterLast($get('a_file'), '/') }}:{{ $get('a_line') }}
                                        <span class="text-secondary">· {{ $get('a_func') }}</span>
                                    @endif
                                </td>
                                <td class="font-monospace small" style="word-break: break-word;">
                                    {{ \Illuminate\Support\Str::limit($body, 220) }}
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-link p-0 raw-toggle"
                                            data-target="raw-{{ $i }}" title="Show raw message">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr id="raw-{{ $i }}" style="display:none;">
                                <td colspan="8" class="bg-light p-2">
                                    <pre class="small font-monospace mb-0" style="white-space: pre-wrap; word-break: break-all;">{{ $get('message') }}</pre>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">
                                No log rows from <code>{{ $sourceIp ?: '(no IP entered)' }}</code> in this time range.
                            </td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-light border small">
            Type a <strong>Source IP</strong> (e.g. <code>10.3.0.10</code> for the JED UCM)
            and click <strong>Search</strong>. Defaults to the last hour.
        </div>
    @endif

</div>

<script>
(function () {
    document.querySelectorAll('.raw-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = document.getElementById(btn.dataset.target);
            if (!row) return;
            row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
            const icon = btn.querySelector('i');
            icon?.classList.toggle('bi-chevron-down');
            icon?.classList.toggle('bi-chevron-up');
        });
    });
})();
</script>
@endsection
