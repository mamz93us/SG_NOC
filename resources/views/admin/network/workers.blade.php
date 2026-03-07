@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h3 mb-1">Workers & Scheduled Tasks</h2>
        <p class="text-muted small mb-0">Monitor and manually trigger background jobs. Because shared hosting may not run a queue worker, use "Run Now" for immediate execution.</p>
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
    <i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- Scheduled Tasks --}}
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock me-2 text-primary"></i>Scheduled Jobs</h5>
                <div class="d-flex gap-2">
                    <form method="POST" action="{{ route('admin.network.workers.run-ping') }}">
                        @csrf
                        <button class="btn btn-primary btn-sm">
                            <i class="bi bi-activity me-1"></i> Run Ping All Now
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.network.workers.run-snmp') }}">
                        @csrf
                        <button class="btn btn-info btn-sm text-white">
                            <i class="bi bi-bar-chart-fill me-1"></i> Run SNMP All Now
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Task</th>
                            <th>Description</th>
                            <th>Schedule</th>
                            <th>Last Run</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tasks as $task)
                        <tr>
                            <td class="ps-4">
                                <span class="badge bg-{{ $task['color'] }} me-2"><i class="bi {{ $task['icon'] }}"></i></span>
                                <strong>{{ $task['name'] }}</strong>
                            </td>
                            <td class="text-muted small">{{ $task['description'] }}</td>
                            <td><code>{{ $task['schedule'] }}</code></td>
                            <td>
                                @if($task['last_run'] === 'Never')
                                    <span class="badge bg-warning text-dark">Never</span>
                                @else
                                    <span class="text-muted">{{ $task['last_run'] }}</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Per-host Discovery --}}
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="mb-0"><i class="bi bi-search me-2 text-success"></i>Per-Host SNMP Discovery</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Host</th>
                            <th>IP</th>
                            <th>Status</th>
                            <th>SNMP</th>
                            <th>Last Ping</th>
                            <th>Ping Checks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($hosts as $host)
                        <tr>
                            <td class="ps-4"><strong>{{ $host->name }}</strong></td>
                            <td><code>{{ $host->ip }}</code></td>
                            <td>
                                @php $colors = ['up' => 'success','down' => 'danger','degraded' => 'warning','unknown' => 'secondary']; @endphp
                                <span class="badge bg-{{ $colors[$host->status] ?? 'secondary' }}">{{ strtoupper($host->status) }}</span>
                            </td>
                            <td>
                                @if($host->snmp_enabled)
                                    <span class="badge bg-info text-white">SNMP {{ strtoupper($host->snmp_version) }}</span>
                                @else
                                    <span class="text-muted small">Disabled</span>
                                @endif
                            </td>
                            <td class="text-muted small">{{ $host->last_ping_at ? $host->last_ping_at->diffForHumans() : 'Never' }}</td>
                            <td><span class="badge bg-light text-dark border">{{ $host->host_checks_count }}</span></td>
                            <td>
                                <div class="d-flex gap-1">
                                    @if($host->snmp_enabled)
                                    <form method="POST" action="{{ route('admin.network.workers.discover-host', $host) }}">
                                        @csrf
                                        <button class="btn btn-outline-success btn-sm" title="Discover Device">
                                            <i class="bi bi-cpu me-1"></i>Device
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.network.workers.discover-interfaces', $host) }}">
                                        @csrf
                                        <button class="btn btn-outline-secondary btn-sm" title="Discover Interfaces">
                                            <i class="bi bi-hdd-network me-1"></i>Interfaces
                                        </button>
                                    </form>
                                    @else
                                        <span class="text-muted small">SNMP disabled</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="text-center py-4 text-muted">No monitored hosts found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Queue Stats --}}
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-stack me-2 text-warning"></i>Pending Queue Jobs</h5>
                <span class="badge bg-warning text-dark">{{ count($queueJobs) }}</span>
            </div>
            <div class="card-body p-0" style="max-height:300px; overflow-y:auto;">
                @forelse($queueJobs as $job)
                <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <code class="small">{{ class_basename(json_decode($job->payload)->displayName ?? $job->queue) }}</code>
                        <div class="text-muted" style="font-size:11px;">Attempts: {{ $job->attempts }} · Added {{ \Carbon\Carbon::createFromTimestamp($job->created_at)->diffForHumans() }}</div>
                    </div>
                </div>
                @empty
                <div class="text-center text-muted py-4">No pending jobs in queue.</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-x-circle me-2 text-danger"></i>Failed Jobs</h5>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-danger">{{ count($failedJobs) }}</span>
                    @if(count($failedJobs) > 0)
                    <form method="POST" action="{{ route('admin.network.workers.clear-failed') }}">
                        @csrf
                        <button class="btn btn-outline-danger btn-sm">Clear All</button>
                    </form>
                    @endif
                </div>
            </div>
            <div class="card-body p-0" style="max-height:300px; overflow-y:auto;">
                @forelse($failedJobs as $job)
                <div class="px-3 py-2 border-bottom">
                    <code class="small">{{ class_basename(json_decode($job->payload)->displayName ?? $job->queue) }}</code>
                    <div class="text-muted" style="font-size:11px;">Failed {{ \Carbon\Carbon::parse($job->failed_at)->diffForHumans() }}</div>
                    <div class="text-danger" style="font-size:11px;">{{ str_limit($job->exception, 100) }}</div>
                </div>
                @empty
                <div class="text-center text-muted py-4"><i class="bi bi-check-circle text-success me-1"></i>No failed jobs.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

@endsection
