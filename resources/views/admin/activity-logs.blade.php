@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-shield-check me-2 text-primary"></i>Audit Log</h1>
</div>

{{-- Filters --}}
<div class="card mb-4 border-0 shadow-sm">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('admin.activity-logs') }}" class="row g-2 align-items-end">
            <div class="col-sm-3">
                <label class="form-label form-label-sm mb-1">Type</label>
                <select name="model_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    @foreach(['Contact','Branch','Extension','UcmServer','User','Setting','RolePermission','PhoneRequestLog'] as $type)
                        <option value="{{ $type }}" {{ request('model_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label form-label-sm mb-1">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    <option value="created"  {{ request('action') === 'created'  ? 'selected' : '' }}>Created</option>
                    <option value="updated"  {{ request('action') === 'updated'  ? 'selected' : '' }}>Updated</option>
                    <option value="deleted"  {{ request('action') === 'deleted'  ? 'selected' : '' }}>Deleted</option>
                    <option value="synced"   {{ request('action') === 'synced'   ? 'selected' : '' }}>Synced</option>
                </select>
            </div>
            <div class="col-sm-3">
                <label class="form-label form-label-sm mb-1">User</label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value="">All Users</option>
                    @foreach(\App\Models\User::orderBy('name')->get() as $u)
                        <option value="{{ $u->id }}" {{ request('user_id') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-auto">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <a href="{{ route('admin.activity-logs') }}" class="btn btn-sm btn-outline-secondary ms-1">
                    <i class="bi bi-x-lg me-1"></i>Clear
                </a>
            </div>
        </form>
    </div>
</div>

@if($logs->count() > 0)
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3" style="width:13%;">Date / Time</th>
                            <th style="width:14%;">User</th>
                            <th style="width:9%;">Action</th>
                            <th style="width:14%;">Subject</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($logs as $log)
                            <tr>
                                <td class="ps-3">
                                    <span class="text-nowrap">{{ $log->created_at->format('d M Y') }}</span><br>
                                    <small class="text-muted">{{ $log->created_at->format('H:i:s') }}</small>
                                </td>
                                <td>
                                    <span class="fw-semibold">{{ $log->user->name ?? '<em>System</em>' }}</span>
                                    @if($log->user?->email)
                                        <br><small class="text-muted">{{ $log->user->email }}</small>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $badgeMap = [
                                            'created' => 'success',
                                            'updated' => 'info',
                                            'deleted' => 'danger',
                                            'synced'  => 'warning',
                                        ];
                                        $color = $badgeMap[$log->action] ?? 'secondary';
                                    @endphp
                                    <span class="badge bg-{{ $color }}">{{ ucfirst($log->action) }}</span>
                                </td>
                                <td>
                                    <strong>{{ ucwords(strtolower(preg_replace('/([A-Z])/', ' $1', $log->model_type))) }}</strong>
                                    @if($log->model_id)
                                        <br><small class="text-muted">#{{ $log->model_id }}</small>
                                    @endif
                                </td>
                                <td>
                                    @if($log->changes)
                                        @php
                                            $json = is_array($log->changes) ? $log->changes : json_decode($log->changes, true);
                                        @endphp
                                        @if(isset($json['old']) && isset($json['new']))
                                            {{-- Show diff --}}
                                            <div class="d-flex gap-2 flex-wrap">
                                                <span class="text-danger small"><i class="bi bi-dash-circle me-1"></i>{{ \Illuminate\Support\Str::limit(json_encode($json['old']), 80) }}</span>
                                                <span class="text-success small"><i class="bi bi-plus-circle me-1"></i>{{ \Illuminate\Support\Str::limit(json_encode($json['new']), 80) }}</span>
                                            </div>
                                        @else
                                            <small class="text-muted font-monospace">{{ \Illuminate\Support\Str::limit(json_encode($json), 120) }}</small>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3 d-flex justify-content-center">
        {{ $logs->appends(request()->query())->links('pagination::bootstrap-5') }}
    </div>
@else
    <div class="alert alert-info text-center border-0 shadow-sm">
        <i class="bi bi-info-circle me-2"></i>No audit log entries found.
    </div>
@endif

@endsection
