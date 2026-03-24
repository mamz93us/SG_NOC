@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-shield-check me-2 text-primary"></i>Audit Log</h4>
        <small class="text-muted">Track all changes made across the platform</small>
    </div>
    <span class="badge bg-secondary fs-6">{{ $logs->total() }} entries</span>
</div>

{{-- Filters --}}
<div class="card mb-4 border-0 shadow-sm">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('admin.activity-logs') }}" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1 fw-semibold">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search changes..." value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1 fw-semibold">Type</label>
                <select name="model_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    @foreach($modelTypes as $type)
                        <option value="{{ $type }}" {{ request('model_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1 fw-semibold">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    @foreach($actions as $act)
                        <option value="{{ $act }}" {{ request('action') === $act ? 'selected' : '' }}>{{ ucfirst($act) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1 fw-semibold">User</label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value="">All Users</option>
                    @foreach(\App\Models\User::orderBy('name')->get() as $u)
                        <option value="{{ $u->id }}" {{ request('user_id') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label form-label-sm mb-1 fw-semibold">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}">
            </div>
            <div class="col-md-1">
                <label class="form-label form-label-sm mb-1 fw-semibold">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}">
            </div>
            <div class="col-md-2">
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
                <table class="table table-hover table-sm mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:14%;">Date / Time</th>
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
                                    <span class="text-nowrap fw-semibold">{{ $log->created_at->format('d M Y') }}</span><br>
                                    <small class="text-muted">{{ $log->created_at->format('H:i:s') }} &middot; {{ $log->created_at->diffForHumans() }}</small>
                                </td>
                                <td>
                                    @if($log->user)
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar-circle" style="width:26px;height:26px;font-size:10px;">{{ strtoupper(substr($log->user->name, 0, 1)) }}</span>
                                        <div>
                                            <span class="fw-semibold">{{ $log->user->name }}</span>
                                            <br><small class="text-muted">{{ $log->user->email }}</small>
                                        </div>
                                    </div>
                                    @else
                                    <span class="text-muted"><i class="bi bi-robot me-1"></i>System</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $badgeMap = [
                                            'created' => 'success',
                                            'updated' => 'info',
                                            'deleted' => 'danger',
                                            'synced'  => 'warning',
                                            'imported'=> 'primary',
                                            'exported'=> 'secondary',
                                        ];
                                        $color = $badgeMap[$log->action] ?? 'secondary';
                                        $iconMap = [
                                            'created' => 'bi-plus-circle',
                                            'updated' => 'bi-pencil',
                                            'deleted' => 'bi-trash',
                                            'synced'  => 'bi-arrow-repeat',
                                            'imported'=> 'bi-download',
                                            'exported'=> 'bi-upload',
                                        ];
                                        $icon = $iconMap[$log->action] ?? 'bi-circle';
                                    @endphp
                                    <span class="badge bg-{{ $color }}"><i class="bi {{ $icon }} me-1"></i>{{ ucfirst($log->action) }}</span>
                                </td>
                                <td>
                                    @php
                                        $typeLabel = ucwords(strtolower(preg_replace('/([A-Z])/', ' $1', $log->model_type)));
                                        $typeIconMap = [
                                            'Contact'       => 'bi-person-lines-fill',
                                            'Branch'        => 'bi-building',
                                            'Extension'     => 'bi-telephone',
                                            'Device'        => 'bi-cpu',
                                            'Printer'       => 'bi-printer',
                                            'Credential'    => 'bi-key',
                                            'User'          => 'bi-person-badge',
                                            'Employee'      => 'bi-person-vcard',
                                            'Setting'       => 'bi-gear',
                                            'VpnTunnel'     => 'bi-shield-lock',
                                            'Incident'      => 'bi-journal-text',
                                            'IspConnection' => 'bi-globe2',
                                            'IpReservation' => 'bi-hdd-rack',
                                            'Landline'      => 'bi-telephone-inbound',
                                        ];
                                        $typeIcon = $typeIconMap[$log->model_type] ?? 'bi-file-earmark';
                                    @endphp
                                    <i class="bi {{ $typeIcon }} me-1 text-muted"></i>
                                    <strong>{{ $typeLabel }}</strong>
                                    @if($log->model_id)
                                        <span class="text-muted ms-1">#{{ $log->model_id }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($log->changes)
                                        @php
                                            $json = is_array($log->changes) ? $log->changes : json_decode($log->changes, true);
                                        @endphp
                                        @if(isset($json['old']) && isset($json['new']) && is_array($json['new']))
                                            <div class="d-flex flex-column gap-1">
                                                @foreach($json['new'] as $field => $newVal)
                                                    @if(isset($json['old'][$field]) && $json['old'][$field] !== $newVal)
                                                    <div>
                                                        <span class="badge bg-light text-dark border me-1">{{ $field }}</span>
                                                        <span class="text-danger"><del>{{ \Illuminate\Support\Str::limit(is_array($json['old'][$field]) ? json_encode($json['old'][$field]) : (string)$json['old'][$field], 40) }}</del></span>
                                                        <i class="bi bi-arrow-right text-muted mx-1" style="font-size:10px"></i>
                                                        <span class="text-success">{{ \Illuminate\Support\Str::limit(is_array($newVal) ? json_encode($newVal) : (string)$newVal, 40) }}</span>
                                                    </div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @elseif(is_array($json))
                                            <div class="d-flex flex-wrap gap-1">
                                                @foreach(array_slice($json, 0, 4) as $k => $v)
                                                <span class="badge bg-light text-dark border">{{ $k }}: {{ \Illuminate\Support\Str::limit(is_array($v) ? json_encode($v) : (string)$v, 30) }}</span>
                                                @endforeach
                                                @if(count($json) > 4)
                                                <span class="text-muted small">+{{ count($json) - 4 }} more</span>
                                                @endif
                                            </div>
                                        @else
                                            <small class="text-muted font-monospace">{{ \Illuminate\Support\Str::limit(is_string($json) ? $json : json_encode($json), 120) }}</small>
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
        {{ $logs->links('pagination::bootstrap-5') }}
    </div>
@else
    <div class="card shadow-sm">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-shield-check display-4 d-block mb-2"></i>
            No audit log entries found matching your filters.
        </div>
    </div>
@endif

@endsection
