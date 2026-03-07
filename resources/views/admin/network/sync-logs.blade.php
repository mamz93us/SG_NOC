@extends('layouts.admin')

@section('content')

@php $hasPending = $logs->contains(fn($l) => $l->status === 'started'); @endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-clock-history me-2 text-primary"></i>Meraki Sync Logs
        </h4>
        <small class="text-muted">History of Meraki data synchronisations</small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        @if($hasPending)
        <span class="text-warning fw-semibold small">
            <span class="spin-icon d-inline-block me-1">&#8635;</span> Sync in progress&hellip;
        </span>
        @endif
        <a href="{{ route('admin.network.overview') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Overview
        </a>
        @can('manage-network-settings')
        <form method="POST" action="{{ route('admin.network.sync') }}">
            @csrf
            <button type="submit" class="btn btn-sm btn-primary" {{ $hasPending ? 'disabled' : '' }}>
                <i class="bi bi-arrow-repeat me-1"></i>Sync Now
            </button>
        </form>
        @endcan
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($logs->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-clock-history display-4 mb-3 d-block"></i>
            <p class="mb-1">No sync logs yet.</p>
            <p class="small">Run a Meraki sync to see results here.</p>
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">#</th>
                        <th>Status</th>
                        <th>Switches</th>
                        <th>Ports</th>
                        <th>Clients</th>
                        <th>Events</th>
                        <th>Duration</th>
                        <th>Started</th>
                        <th>Completed</th>
                        <th class="pe-3">Error</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                    <tr>
                        <td class="ps-3 text-muted small">{{ $log->id }}</td>
                        <td>
                            <span class="badge {{ $log->statusBadgeClass() }}">
                                @if($log->status === 'started')
                                <span class="spin-icon">&#8635;</span>
                                @else
                                <i class="bi {{ $log->status === 'completed' ? 'bi-check-circle' : 'bi-x-circle' }} me-1"></i>
                                @endif
                                {{ ucfirst($log->status) }}
                            </span>
                        </td>
                        <td>
                            @if($log->switches_synced > 0)
                            <span class="fw-semibold">{{ $log->switches_synced }}</span>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($log->ports_synced > 0)
                            {{ number_format($log->ports_synced) }}
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($log->clients_synced > 0)
                            {{ number_format($log->clients_synced) }}
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($log->events_synced > 0)
                            {{ number_format($log->events_synced) }}
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @php $dur = $log->durationSeconds(); @endphp
                            @if($dur !== null)
                                @if($dur >= 60)
                                    {{ floor($dur / 60) }}m {{ $dur % 60 }}s
                                @else
                                    {{ $dur }}s
                                @endif
                            @elseif($log->status === 'started')
                                <span class="text-muted fst-italic">running…</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="small text-nowrap">
                            @if($log->started_at)
                                <span title="{{ $log->started_at->format('Y-m-d H:i:s') }}">
                                    {{ $log->started_at->diffForHumans() }}
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="small text-nowrap">
                            @if($log->completed_at)
                                <span title="{{ $log->completed_at->format('Y-m-d H:i:s') }}">
                                    {{ $log->completed_at->format('H:i:s') }}
                                </span>
                            @elseif($log->status === 'started')
                                <span class="text-muted fst-italic">in progress</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="pe-3">
                            @if($log->error_message)
                            <span class="text-danger small" title="{{ $log->error_message }}">
                                <i class="bi bi-exclamation-circle me-1"></i>
                                {{ Str::limit($log->error_message, 60) }}
                            </span>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
        <div class="px-3 py-2 border-top">
            {{ $logs->links() }}
        </div>
        @endif
        @endif
    </div>
</div>

@endsection

@push('styles')
<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.spin-icon { display: inline-block; animation: spin 1s linear infinite; }
</style>
@endpush

@if($hasPending)
@push('scripts')
<script>setTimeout(() => location.reload(), 5000);</script>
@endpush
@endif
