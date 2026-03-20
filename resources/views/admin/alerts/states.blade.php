@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex align-items-center mb-4">
        <a href="{{ route('admin.alert-rules.index') }}" class="btn btn-sm btn-outline-secondary me-3">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h4 class="mb-0 fw-bold">
                <i class="bi bi-list-check me-2"></i>Alert States — {{ $alertRule->name }}
            </h4>
            <small class="text-muted">
                <span class="badge bg-{{ $alertRule->severityBadge() }} me-1">{{ ucfirst($alertRule->severity) }}</span>
                Target: {{ ucfirst($alertRule->target_type) }}
                @if($alertRule->sensor_class)
                    &middot; Class: <code>{{ $alertRule->sensor_class }}</code>
                @endif
                &middot; Condition: <code>value {{ $alertRule->operator }} {{ $alertRule->threshold_value }}</code>
            </small>
        </div>
        <a href="{{ route('admin.alert-rules.edit', $alertRule) }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i>Edit Rule
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body p-0">
            @if($states->isEmpty())
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-shield-check display-4 d-block mb-3"></i>
                    <p class="mb-0">No alert states recorded yet for this rule.</p>
                    <small>States are created automatically when the evaluator processes matching entities.</small>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Entity</th>
                                <th class="text-center">State</th>
                                <th class="text-end">Triggered Value</th>
                                <th>First Triggered</th>
                                <th>Last Alerted</th>
                                <th class="text-center">Alert Count</th>
                                <th>Acknowledged By</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($states as $state)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ ucfirst($state->entity_type) }} #{{ $state->entity_id }}</div>
                                    @if($state->recovered_at)
                                        <small class="text-success"><i class="bi bi-check-circle me-1"></i>Recovered {{ $state->recovered_at->diffForHumans() }}</small>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-{{ $state->stateBadge() }}">
                                        {{ ucfirst($state->state) }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    @if($state->triggered_value !== null)
                                        <code>{{ $state->triggered_value }}</code>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
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
                                <td class="text-center">
                                    @if($state->alert_count > 0)
                                        <span class="badge bg-secondary">{{ $state->alert_count }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                                <td>
                                    @if($state->acknowledged_by)
                                        <small>{{ $state->acknowledged_by }}</small>
                                        @if($state->acknowledged_at)
                                            <br><small class="text-muted">{{ $state->acknowledged_at->diffForHumans() }}</small>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if($state->state === 'alerted')
                                        <form method="POST" action="{{ route('admin.alert-states.acknowledge', $state) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                <i class="bi bi-check-circle me-1"></i>Acknowledge
                                            </button>
                                        </form>
                                    @elseif($state->state === 'acknowledged')
                                        <span class="badge bg-warning text-dark">
                                            <i class="bi bi-check-circle me-1"></i>Acknowledged
                                        </span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($states->hasPages())
                    <div class="p-3">
                        {{ $states->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection
