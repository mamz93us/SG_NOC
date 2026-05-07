@extends('layouts.admin')

@section('title', 'Discovered SNMP Devices')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Discovered Devices</h4>
            <small class="text-muted">
                nmap finds from each branch, awaiting your review. Approve to start polling, or reject to suppress for 30 days.
            </small>
        </div>
        <a href="{{ route('admin.snmp-devices.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to managed devices
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- ─── Filter ──────────────────────────────────────────────────── --}}
    <form method="GET" class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 small align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Branch</label>
                    <select name="branch" class="form-select form-select-sm">
                        <option value="">all branches</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->code }}" @if(request('branch')===$b->code) selected @endif>
                                {{ $b->name }} ({{ $b->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="pending"  @if($status==='pending')  selected @endif>pending review</option>
                        <option value="approved" @if($status==='approved') selected @endif>approved</option>
                        <option value="rejected" @if($status==='rejected') selected @endif>rejected</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                </div>
            </div>
        </div>
    </form>

    {{-- ─── Table ───────────────────────────────────────────────────── --}}
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">Branch</th>
                        <th style="width:130px;">Host</th>
                        <th style="width:120px;">MAC</th>
                        <th>sysDescr</th>
                        <th style="width:140px;">Suggested type</th>
                        <th style="width:60px;">SNMP</th>
                        <th style="width:140px;">Last seen</th>
                        @if($status === 'pending')
                            <th style="width:280px;">Action</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                @forelse($rows as $r)
                    <tr>
                        <td><span class="badge bg-secondary">{{ $r->branch?->code }}</span></td>
                        <td class="font-monospace small">{{ $r->host }}</td>
                        <td class="font-monospace small text-muted">{{ $r->mac ?: '—' }}</td>
                        <td class="small text-truncate" style="max-width:300px;" title="{{ $r->sys_descr }}">
                            {{ $r->sys_descr ?: $r->sys_name ?: '—' }}
                        </td>
                        <td>
                            @if($r->suggested_type)
                                <span class="badge bg-light text-dark">{{ $types[$r->suggested_type] ?? $r->suggested_type }}</span>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td>
                            @if($r->snmp_responding)
                                <span class="badge bg-success">yes</span>
                            @else
                                <span class="badge bg-secondary">no</span>
                            @endif
                        </td>
                        <td class="small text-muted">{{ $r->last_seen_at?->diffForHumans() }}</td>

                        @if($status === 'pending')
                            <td>
                                {{-- Approval form: pick name + type + community, then submit --}}
                                <form action="{{ route('admin.snmp-devices.approve-discovered', $r) }}" method="POST"
                                      class="d-flex gap-1 align-items-center">
                                    @csrf
                                    <input type="text" name="name"
                                           value="{{ $r->sys_name ?: $r->host }}"
                                           class="form-control form-control-sm" placeholder="name"
                                           required style="max-width:120px;">
                                    <select name="device_type" class="form-select form-select-sm" required style="max-width:130px;">
                                        @foreach($types as $key => $label)
                                            <option value="{{ $key }}" @if($r->suggested_type===$key) selected @endif>{{ $key }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="snmp_community"
                                           class="form-control form-control-sm font-monospace"
                                           placeholder="public" style="max-width:90px;">
                                    <button type="submit" class="btn btn-sm btn-success" title="Approve">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <form action="{{ route('admin.snmp-devices.reject-discovered', $r) }}" method="POST"
                                      class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-danger mt-1" title="Reject (suppress 30 days)">
                                        <i class="bi bi-x-lg"></i> reject
                                    </button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $status==='pending' ? 8 : 7 }}" class="text-center text-muted py-5">
                            No {{ $status }} discoveries.
                            @if($status==='pending')
                                <br><small>Branch VM nmap scans run every hour — fresh finds will appear here.</small>
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
        @if($rows->hasPages())
            <div class="card-footer">{{ $rows->links() }}</div>
        @endif
    </div>
</div>
@endsection
