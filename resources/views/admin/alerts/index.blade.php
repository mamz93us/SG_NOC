@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-shield-exclamation me-2"></i>Alert Rules</h4>
            <small class="text-muted">Define conditions that trigger notifications when sensor values cross thresholds.</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.alerts.dashboard') }}" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-activity me-1"></i>Active Alerts Dashboard
            </a>
            <a href="{{ route('admin.alert-rules.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i>New Rule
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body p-0">
            @if($rules->isEmpty())
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-shield-check display-4 d-block mb-3"></i>
                    <p class="mb-0">No alert rules defined yet.</p>
                    <a href="{{ route('admin.alert-rules.create') }}" class="btn btn-primary btn-sm mt-3">
                        <i class="bi bi-plus-lg me-1"></i>Create First Rule
                    </a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Name</th>
                                <th>Severity</th>
                                <th>Target</th>
                                <th>Condition</th>
                                <th class="text-center">Active Alerts</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rules as $rule)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $rule->name }}</div>
                                    @if($rule->description)
                                        <small class="text-muted">{{ Str::limit($rule->description, 60) }}</small>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-{{ $rule->severityBadge() }}">
                                        {{ ucfirst($rule->severity) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">{{ ucfirst($rule->target_type) }}</span>
                                    @if($rule->sensor_class)
                                        <br><small class="text-muted">{{ $rule->sensor_class }}</small>
                                    @endif
                                </td>
                                <td>
                                    <code class="small">value {{ $rule->operator }} {{ $rule->threshold_value }}</code>
                                </td>
                                <td class="text-center">
                                    @if($rule->active_count > 0)
                                        <a href="{{ route('admin.alert-rules.states', $rule) }}" class="badge bg-danger text-decoration-none">
                                            {{ $rule->active_count }}
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($rule->disabled)
                                        <span class="badge bg-secondary">Disabled</span>
                                    @else
                                        <span class="badge bg-success">Enabled</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('admin.alert-rules.states', $rule) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View States">
                                        <i class="bi bi-list-check"></i>
                                    </a>
                                    <a href="{{ route('admin.alert-rules.edit', $rule) }}"
                                       class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="{{ route('admin.alert-rules.destroy', $rule) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('Delete this alert rule? All associated states will also be deleted.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
