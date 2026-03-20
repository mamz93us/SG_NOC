@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-activity me-2"></i>Active Alerts Dashboard</h4>
            <small class="text-muted">Real-time view of all firing and acknowledged alert states.</small>
        </div>
        <a href="{{ route('admin.alert-rules.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-shield-exclamation me-1"></i>Manage Rules
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Summary Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-bg-dark shadow-sm text-center py-3">
                <div class="card-body py-2">
                    <div class="display-6 fw-bold">{{ $totalActive }}</div>
                    <small class="text-muted">Total Active</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-bg-danger shadow-sm text-center py-3">
                <div class="card-body py-2">
                    <div class="display-6 fw-bold">{{ $totalCritical }}</div>
                    <small>Critical</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-bg-warning shadow-sm text-center py-3">
                <div class="card-body py-2">
                    <div class="display-6 fw-bold">{{ $totalWarning }}</div>
                    <small>Warning</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm text-center py-3">
                <div class="card-body py-2">
                    <div class="display-6 fw-bold text-warning">{{ $totalAcknowledged }}</div>
                    <small class="text-muted">Acknowledged</small>
                </div>
            </div>
        </div>
    </div>

    @if($activeStates->isEmpty())
        <div class="card shadow-sm">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-shield-check display-4 d-block mb-3 text-success"></i>
                <h5 class="text-success">All Clear</h5>
                <p class="mb-0">No active alerts at this time.</p>
            </div>
        </div>
    @else

        {{-- Critical Alerts --}}
        @php $criticalAlerts = $activeStates->filter(fn($s) => $s->rule?->severity === 'critical'); @endphp
        @if($criticalAlerts->isNotEmpty())
        <div class="card border-danger shadow-sm mb-4">
            <div class="card-header bg-danger text-white fw-semibold">
                <i class="bi bi-exclamation-octagon-fill me-2"></i>Critical Alerts
                <span class="badge bg-white text-danger ms-2">{{ $criticalAlerts->count() }}</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Rule</th>
                                <th>Entity</th>
                                <th class="text-end">Value</th>
                                <th>State</th>
                                <th>Since</th>
                                <th>Last Alert</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($criticalAlerts as $state)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.alert-rules.states', $state->alert_rule_id) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $state->rule?->name ?? '—' }}
                                    </a>
                                </td>
                                <td>
                                    <span class="badge bg-secondary me-1">{{ ucfirst($state->entity_type) }}</span>
                                    #{{ $state->entity_id }}
                                </td>
                                <td class="text-end">
                                    @if($state->triggered_value !== null)
                                        <code>{{ $state->triggered_value }}</code>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-{{ $state->stateBadge() }}">{{ ucfirst($state->state) }}</span>
                                </td>
                                <td>
                                    @if($state->first_triggered_at)
                                        <span title="{{ $state->first_triggered_at->format('Y-m-d H:i:s') }}">
                                            {{ $state->first_triggered_at->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($state->last_alerted_at)
                                        <span title="{{ $state->last_alerted_at->format('Y-m-d H:i:s') }}">
                                            {{ $state->last_alerted_at->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if($state->state === 'alerted')
                                        <form method="POST" action="{{ route('admin.alert-states.acknowledge', $state) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                <i class="bi bi-check-circle me-1"></i>Ack
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif

        {{-- Warning Alerts --}}
        @php $warningAlerts = $activeStates->filter(fn($s) => $s->rule?->severity === 'warning'); @endphp
        @if($warningAlerts->isNotEmpty())
        <div class="card border-warning shadow-sm mb-4">
            <div class="card-header bg-warning text-dark fw-semibold">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>Warning Alerts
                <span class="badge bg-dark text-warning ms-2">{{ $warningAlerts->count() }}</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Rule</th>
                                <th>Entity</th>
                                <th class="text-end">Value</th>
                                <th>State</th>
                                <th>Since</th>
                                <th>Last Alert</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($warningAlerts as $state)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.alert-rules.states', $state->alert_rule_id) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $state->rule?->name ?? '—' }}
                                    </a>
                                </td>
                                <td>
                                    <span class="badge bg-secondary me-1">{{ ucfirst($state->entity_type) }}</span>
                                    #{{ $state->entity_id }}
                                </td>
                                <td class="text-end">
                                    @if($state->triggered_value !== null)
                                        <code>{{ $state->triggered_value }}</code>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-{{ $state->stateBadge() }}">{{ ucfirst($state->state) }}</span>
                                </td>
                                <td>
                                    @if($state->first_triggered_at)
                                        <span title="{{ $state->first_triggered_at->format('Y-m-d H:i:s') }}">
                                            {{ $state->first_triggered_at->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($state->last_alerted_at)
                                        <span title="{{ $state->last_alerted_at->format('Y-m-d H:i:s') }}">
                                            {{ $state->last_alerted_at->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if($state->state === 'alerted')
                                        <form method="POST" action="{{ route('admin.alert-states.acknowledge', $state) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                <i class="bi bi-check-circle me-1"></i>Ack
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif

    @endif
</div>
@endsection
