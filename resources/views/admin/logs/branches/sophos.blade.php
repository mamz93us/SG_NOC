@extends('layouts.admin')

@section('title', 'Branch Logs — Sophos firewalls')

@php
    $subtypeBadge = function (?string $s) {
        return match (strtolower((string) $s)) {
            'denied'    => 'bg-danger',
            'allowed'   => 'bg-success',
            'violation' => 'bg-warning text-dark',
            default     => 'bg-secondary',
        };
    };
@endphp

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Branch Logs — Sophos Firewalls</h4>
            <small class="text-muted">
                Columnar view of firewall events across all branches.
                Same source data as <a href="{{ route('admin.logs.branches.index') }}">Branch Logs</a>,
                pre-filtered to Sophos messages.
            </small>
        </div>
        @if(count($branches))
            <span class="badge bg-info text-dark">
                {{ count($branches) }} branch{{ count($branches) === 1 ? '' : 'es' }} reachable
            </span>
        @endif
    </div>

    {{-- ─── Filters ─────────────────────────────────────────────────── --}}
    <form method="GET" action="{{ route('admin.logs.branches.sophos') }}" class="card mb-3">
        <div class="card-body py-2">
            <input type="hidden" name="search" value="1">
            <div class="row g-2 small align-items-end">

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
                    <label class="form-label small text-muted mb-1">Subtype</label>
                    <select name="sophos_subtype" class="form-select form-select-sm">
                        <option value="">any</option>
                        <option value="Denied"    @if($filters['sophos_subtype']==='Denied')    selected @endif>Denied</option>
                        <option value="Allowed"   @if($filters['sophos_subtype']==='Allowed')   selected @endif>Allowed</option>
                        <option value="Violation" @if($filters['sophos_subtype']==='Violation') selected @endif>Violation</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Src IP</label>
                    <input type="text" name="sophos_src_ip"
                           value="{{ $filters['sophos_src_ip'] }}"
                           placeholder="10.3.0.68"
                           class="form-control form-control-sm font-monospace">
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Dst IP</label>
                    <input type="text" name="sophos_dst_ip"
                           value="{{ $filters['sophos_dst_ip'] }}"
                           placeholder="8.8.8.8"
                           class="form-control form-control-sm font-monospace">
                </div>

                <div class="col-md-3 mt-2">
                    <label class="form-label small text-muted mb-1">Free-text in message</label>
                    <input type="text" name="q"
                           value="{{ $filters['q'] }}"
                           placeholder="rule name, app, etc."
                           class="form-control form-control-sm">
                </div>

                <div class="col-md-3 mt-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-search me-1"></i>Search
                    </button>
                    <a href="{{ route('admin.logs.branches.sophos') }}" class="btn btn-sm btn-outline-secondary">
                        Clear
                    </a>
                </div>
            </div>
        </div>
    </form>

    {{-- ─── Results ─────────────────────────────────────────────────── --}}
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

                    @if(!empty($results['errors']))
                        <span class="text-warning">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            {{ count($results['errors']) }} branch error(s):
                            @foreach($results['errors'] as $bid => $err)
                                <span title="{{ $err }}">{{ $bid }}</span>@if(!$loop->last), @endif
                            @endforeach
                        </span>
                    @endif
                </div>

                <div class="table-responsive" style="max-height: 75vh;">
                    <table class="table table-sm table-striped table-hover small mb-0 align-middle">
                        <thead class="sticky-top bg-light">
                            <tr>
                                <th style="width:140px;">Time (UTC)</th>
                                <th style="width: 56px;">Branch</th>
                                <th style="width: 90px;">Component</th>
                                <th style="width: 80px;">Action</th>
                                <th style="width:170px;">Username</th>
                                <th style="width: 50px;">Rule</th>
                                <th style="width:160px;">Rule name</th>
                                <th style="width:110px;">Iface in→out</th>
                                <th>Source</th>
                                <th>Destination</th>
                                <th style="width: 60px;">Proto</th>
                                <th style="width: 32px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($results['results'] as $i => $r)
                            @php
                                // Defensive — older branch APIs may not return every column;
                                // the message string + extra-KV pre-parse fills the gaps.
                                $get = fn ($k, $default = '') => $r[$k] ?? $default;
                                $subtype = $get('sophos_log_subtype');
                            @endphp
                            <tr>
                                <td class="text-nowrap font-monospace">
                                    {{ \Illuminate\Support\Str::of($get('received_at'))->limit(19, '') }}
                                </td>
                                <td><span class="badge bg-secondary">{{ $get('branch_id') }}</span></td>
                                <td>
                                    <i class="bi bi-shield-shaded me-1 text-{{ $subtype === 'Denied' ? 'danger' : 'success' }}"></i>
                                    <span title="{{ $get('sophos_log_type') }}">{{ $get('sophos_log_component') ?: '—' }}</span>
                                </td>
                                <td>
                                    <span class="badge {{ $subtypeBadge($subtype) }}">
                                        {{ $subtype ?: '—' }}
                                    </span>
                                </td>
                                <td class="text-truncate" style="max-width:170px;" title="{{ $get('sophos_user_name') }}">
                                    {{ $get('sophos_user_name') }}
                                </td>
                                <td class="text-end font-monospace small text-muted">
                                    {{ $get('kv_fw_rule_id') }}
                                </td>
                                <td class="text-truncate" style="max-width:160px;" title="{{ $get('sophos_fw_rule_name') }}">
                                    {{ $get('sophos_fw_rule_name') }}
                                </td>
                                <td class="font-monospace small">
                                    {{ $get('kv_in_interface') ?: '?' }}<i class="bi bi-arrow-right mx-1 text-muted"></i>{{ $get('kv_out_interface') ?: '?' }}
                                </td>
                                <td class="font-monospace small">
                                    {{ $get('sophos_src_ip') }}@if($get('sophos_src_port')):<span class="text-muted">{{ $get('sophos_src_port') }}</span>@endif
                                </td>
                                <td class="font-monospace small">
                                    {{ $get('sophos_dst_ip') }}@if($get('sophos_dst_port')):<span class="text-muted">{{ $get('sophos_dst_port') }}</span>@endif
                                    @if($get('kv_dst_country') && $get('kv_dst_country') !== 'R1')
                                        <span class="badge bg-light text-dark ms-1">{{ $get('kv_dst_country') }}</span>
                                    @endif
                                </td>
                                <td>{{ $get('sophos_protocol') }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-link p-0 raw-toggle"
                                            data-target="raw-{{ $i }}" title="Show raw message">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr id="raw-{{ $i }}" style="display:none;">
                                <td colspan="12" class="bg-light p-2">
                                    <pre class="small font-monospace mb-0" style="white-space: pre-wrap; word-break: break-all;">{{ $r['message'] }}</pre>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="12" class="text-center text-muted py-4">
                                No firewall events in this time range.
                            </td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-light border small">
            Pick a time range and click <strong>Search</strong>. Defaults to the last hour.
            Tip: queries with no filter on a busy branch can return tens of thousands of rows
            — narrow with <em>Subtype = Denied</em> or a <em>Dst IP</em> first.
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
            if (icon) icon.classList.toggle('bi-chevron-down');
            if (icon) icon.classList.toggle('bi-chevron-up');
        });
    });
})();
</script>
@endsection
