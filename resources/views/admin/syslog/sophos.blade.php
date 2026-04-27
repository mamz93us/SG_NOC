@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-shield-fill-check me-2 text-primary"></i>Sophos Firewall — Log Viewer</h4>
        <small class="text-muted">Parsed key-value events from Sophos syslog</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.syslog.index', ['source_type' => 'sophos']) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list me-1"></i>Raw view
        </a>
        <a href="{{ route('admin.syslog.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>All Syslog
        </a>
        @can('manage-syslog')
        <form method="POST" action="{{ route('admin.syslog.clear') }}" class="d-inline"
              onsubmit="var v = prompt('This will DELETE every row in syslog_messages (not just Sophos).\n\nType CLEAR to confirm.'); if (v === 'CLEAR') { this.confirm.value = v; return true; } return false;">
            @csrf
            <input type="hidden" name="confirm" value="">
            <button type="submit" class="btn btn-outline-danger btn-sm" title="Wipe all syslog rows">
                <i class="bi bi-trash3 me-1"></i>Clear all
            </button>
        </form>
        @endcan
    </div>
</div>

{{-- Filters --}}
<form method="GET" class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">Host</label>
                <input type="text" name="host" value="{{ $filters['host'] }}"
                       class="form-control form-control-sm" placeholder="jed.samirgroup.net">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Component</label>
                <select name="component" class="form-select form-select-sm">
                    <option value="">Any</option>
                    @foreach($components as $c)
                    <option value="{{ $c }}" {{ $filters['component'] === $c ? 'selected' : '' }}>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Subtype</label>
                <select name="subtype" class="form-select form-select-sm">
                    <option value="">Any</option>
                    @foreach($subtypes as $s)
                    <option value="{{ $s }}" {{ $filters['subtype'] === $s ? 'selected' : '' }}>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small mb-1">FW rule</label>
                <input type="text" name="fw_rule" value="{{ $filters['fw_rule'] }}"
                       class="form-control form-control-sm" placeholder="33">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Src IP</label>
                <input type="text" name="src_ip" value="{{ $filters['src_ip'] }}"
                       class="form-control form-control-sm" placeholder="10.1.1.23">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Dst IP</label>
                <input type="text" name="dst_ip" value="{{ $filters['dst_ip'] }}"
                       class="form-control form-control-sm" placeholder="10.1.1.76">
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
                       class="form-control form-control-sm" placeholder="Search raw message body…">
                <button type="submit" class="btn btn-primary btn-sm flex-shrink-0"><i class="bi bi-funnel"></i> Filter</button>
                <a href="{{ route('admin.syslog.sophos') }}" class="btn btn-outline-secondary btn-sm flex-shrink-0">Reset</a>
            </div>
        </div>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($messages->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-shield display-4 d-block mb-2"></i>
            No Sophos events match these filters.
            @php $hasUnparsed = \App\Models\SyslogMessage::where('source_type','sophos')->whereNull('parsed')->exists(); @endphp
            @if($hasUnparsed)
            <div class="small mt-2">
                <em>Sophos rows exist but haven't been parsed yet — wait a minute for the scheduler, or visit
                <a href="{{ route('admin.syslog.rules.index') }}">Alert Rules</a> and click <em>Run now</em>.</em>
            </div>
            @endif
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width:30px"></th>
                        <th style="width:160px">Time</th>
                        <th style="width:120px">Log comp</th>
                        <th style="width:90px">Subtype</th>
                        <th style="width:130px">Username</th>
                        <th style="width:60px">FW rule</th>
                        <th style="min-width:160px">Firewall rule name</th>
                        <th style="width:60px">NAT rule</th>
                        <th style="width:80px">In iface</th>
                        <th style="width:80px">Out iface</th>
                        <th style="width:120px">Src IP</th>
                        <th style="width:120px">Dst IP</th>
                        <th style="width:70px">Src port</th>
                        <th style="width:70px">Dst port</th>
                        <th style="width:70px">Protocol</th>
                        <th class="pe-3" style="width:70px">Bytes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($messages as $m)
                    @php
                        $p = $m->parsed ?? [];
                        $subtype = $p['log_subtype'] ?? null;
                        $rowClass = match (strtolower((string) $subtype)) {
                            'denied','dropped' => 'table-danger',
                            'allowed'          => '',
                            default            => '',
                        };
                        $iconClass = match (strtolower((string) $subtype)) {
                            'denied','dropped' => 'bi-shield-x text-danger',
                            'allowed'          => 'bi-shield-check text-success',
                            default            => 'bi-shield text-muted',
                        };
                        $bytes = (int) ($p['bytes_sent'] ?? 0) + (int) ($p['bytes_received'] ?? 0);
                    @endphp
                    <tr class="{{ $rowClass }}" style="cursor:pointer" onclick="window.location='{{ route('admin.syslog.show', $m->id) }}'">
                        <td class="ps-3"><i class="bi {{ $iconClass }}"></i></td>
                        <td class="text-nowrap" title="{{ $m->received_at->toDateTimeString() }}">{{ $m->received_at->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $p['log_component'] ?? '—' }}</td>
                        <td>{{ $subtype ?? '—' }}</td>
                        <td class="text-truncate" style="max-width:130px" title="{{ $p['user_name'] ?? $p['user_group'] ?? '' }}">
                            {{ $p['user_name'] ?? $p['user_group'] ?? '—' }}
                        </td>
                        <td>{{ $p['fw_rule_id'] ?? '—' }}</td>
                        <td class="text-truncate" style="max-width:200px" title="{{ $p['fw_rule_name'] ?? '' }}">{{ $p['fw_rule_name'] ?? '—' }}</td>
                        <td>{{ $p['nat_rule_id'] ?? '—' }}</td>
                        <td>{{ $p['in_interface'] ?? $p['in_display_interface'] ?? '—' }}</td>
                        <td>{{ $p['out_interface'] ?? $p['out_display_interface'] ?? '—' }}</td>
                        <td class="font-monospace small">{{ $p['src_ip'] ?? '—' }}</td>
                        <td class="font-monospace small">{{ $p['dst_ip'] ?? '—' }}</td>
                        <td>{{ $p['src_port'] ?? '—' }}</td>
                        <td>{{ $p['dst_port'] ?? '—' }}</td>
                        <td>{{ $p['protocol'] ?? '—' }}</td>
                        <td class="pe-3 small text-muted text-end">{{ $bytes ? number_format($bytes) : '—' }}</td>
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
