@extends('layouts.admin')

@section('title', 'Branch Logs')

@php
    $displayTz = request('tz') ?: config('app.timezone', 'UTC');
    $tzLabel   = (function ($tz) {
        try {
            $off = (new DateTime('now', new DateTimeZone($tz)))->format('P');
            return $tz . ' (UTC' . ($off === '+00:00' ? '' : $off) . ')';
        } catch (Throwable) { return $tz; }
    })($displayTz);
    $fmtTime = function (?string $utc) use ($displayTz) {
        if (!$utc) return '';
        try {
            return \Illuminate\Support\Carbon::parse($utc, 'UTC')
                ->setTimezone($displayTz)->format('Y-m-d H:i:s');
        } catch (Throwable) { return $utc; }
    };
@endphp

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Branch Logs</h4>
            <small class="text-muted">
                Search syslog stored on the per-branch VMs.
                Logs never leave the branch — this page queries each VM's API in parallel.
            </small>
        </div>
        @if(count($branches))
            <span class="badge bg-info text-dark">
                {{ count($branches) }} branch{{ count($branches) === 1 ? '' : 'es' }} reachable
            </span>
        @else
            <span class="badge bg-warning text-dark">
                No branches configured — see config/branches.php
            </span>
        @endif
    </div>

    {{-- ─── Filter form ─────────────────────────────────────────────── --}}
    <form method="GET" action="{{ route('admin.logs.branches.index') }}" class="card mb-3">
        <div class="card-body">
            <input type="hidden" name="search" value="1">
            <div class="row g-2">

                <div class="col-md-3">
                    <label class="form-label small text-muted">Branches</label>
                    <select name="branches" class="form-select form-select-sm" multiple size="3"
                            onchange="this.form.elements.branches_csv.value = Array.from(this.selectedOptions).map(o=>o.value).join(',')">
                        @foreach($branches as $id => $info)
                            <option value="{{ $id }}"
                                @if(in_array($id, $selectedBranches)) selected @endif>
                                {{ $info['name'] }} ({{ $id }})
                            </option>
                        @endforeach
                    </select>
                    <input type="hidden" name="branches_csv" value="{{ implode(',', $selectedBranches) }}">
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted">From</label>
                    <input type="datetime-local" name="from"
                           value="{{ $filters['from'] }}"
                           class="form-control form-control-sm">
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted">To</label>
                    <input type="datetime-local" name="to"
                           value="{{ $filters['to'] }}"
                           class="form-control form-control-sm">
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted">Source / Host</label>
                    <input type="text" name="source"
                           value="{{ $filters['source'] }}"
                           placeholder="10.1.0.1, GRP2602W"
                           class="form-control form-control-sm">
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">Free-text in message</label>
                    <input type="text" name="q"
                           value="{{ $filters['q'] }}"
                           placeholder="Denied, SECURITY, etc."
                           class="form-control form-control-sm">
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted">Severity ≤</label>
                    <select name="severity" class="form-select form-select-sm">
                        <option value="">any</option>
                        <option value="0" @if($filters['severity']==='0') selected @endif>0 emerg</option>
                        <option value="1" @if($filters['severity']==='1') selected @endif>1 alert</option>
                        <option value="2" @if($filters['severity']==='2') selected @endif>2 crit</option>
                        <option value="3" @if($filters['severity']==='3') selected @endif>3 error</option>
                        <option value="4" @if($filters['severity']==='4') selected @endif>4 warning</option>
                        <option value="5" @if($filters['severity']==='5') selected @endif>5 notice</option>
                        <option value="6" @if($filters['severity']==='6') selected @endif>6 info</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted">Program</label>
                    <input type="text" name="program"
                           value="{{ $filters['program'] }}"
                           placeholder="asterisk, kernel"
                           class="form-control form-control-sm">
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted">Sophos subtype</label>
                    <select name="sophos_subtype" class="form-select form-select-sm">
                        <option value="">any</option>
                        <option value="Denied"  @if($filters['sophos_subtype']==='Denied')  selected @endif>Denied</option>
                        <option value="Allowed" @if($filters['sophos_subtype']==='Allowed') selected @endif>Allowed</option>
                        <option value="Violation" @if($filters['sophos_subtype']==='Violation') selected @endif>Violation</option>
                    </select>
                </div>

                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-search me-1"></i>Search
                    </button>
                    <a href="{{ route('admin.logs.branches.index') }}" class="btn btn-sm btn-outline-secondary">
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

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted">
                        <strong>{{ number_format($results['total']) }}</strong> total matches across
                        <strong>{{ count($results['branches']) }}</strong> branches —
                        showing first {{ count($results['results']) }} —
                        took {{ $results['took_ms'] }} ms
                    </small>
                    <a href="?{{ http_build_query(array_merge(request()->query(), ['export' => 'csv', 'rows' => 5000])) }}"
                       class="btn btn-sm btn-outline-success"
                       title="Up to 5,000 rows per branch (Excel-friendly UTF-8)">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
                    </a>

                    @if(!empty($results['errors']))
                        <small class="text-warning">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            {{ count($results['errors']) }} branch error(s):
                            @foreach($results['errors'] as $bid => $err)
                                <span title="{{ $err }}">{{ $bid }}</span>@if(!$loop->last), @endif
                            @endforeach
                        </small>
                    @endif
                </div>

                <div class="table-responsive" style="max-height: 70vh;">
                    <table class="table table-sm table-striped table-hover small mb-0">
                        <thead class="sticky-top bg-light">
                            <tr>
                                <th style="width:170px;" title="{{ $tzLabel }}">Time</th>
                                <th style="width: 60px;">Branch</th>
                                <th style="width: 90px;">Severity</th>
                                <th style="width:140px;">Source</th>
                                <th style="width:110px;">Program</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($results['results'] as $r)
                            <tr>
                                <td class="text-nowrap font-monospace" title="UTC: {{ $r['received_at'] }}">
                                    {{ $fmtTime($r['received_at']) }}
                                </td>
                                <td><span class="badge bg-secondary">{{ $r['branch_id'] }}</span></td>
                                <td>
                                    @php $sev = (int) ($r['severity'] ?? 6); @endphp
                                    <span class="badge bg-{{ ['danger','danger','danger','danger','warning','info','info','secondary'][$sev] ?? 'secondary' }}">
                                        {{ ['emerg','alert','crit','err','warn','notice','info','debug'][$sev] ?? $sev }}
                                    </span>
                                </td>
                                <td class="text-nowrap">{{ $r['source'] ?? $r['source_ip'] }}</td>
                                <td>{{ $r['program'] }}</td>
                                <td class="font-monospace" style="word-break: break-word;">
                                    {{ $r['message'] }}
                                    @if(!empty($r['sophos_log_subtype']))
                                        <span class="badge bg-light text-dark ms-1">{{ $r['sophos_log_subtype'] }}</span>
                                        @if(!empty($r['sophos_dst_ip']))
                                            <span class="text-muted ms-1">→ {{ $r['sophos_dst_ip'] }}:{{ $r['sophos_dst_port'] }}</span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">
                                No matches in the selected time range.
                            </td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

</div>
@endsection
