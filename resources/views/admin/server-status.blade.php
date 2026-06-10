@extends('layouts.admin')
@section('content')

@php
    $host = $snapshot['host']; $cpu = $snapshot['cpu']; $mem = $snapshot['memory'];
    $app = $snapshot['app'];

    $barColor = fn (?int $p) => $p === null ? 'bg-secondary' : ($p < 50 ? 'bg-success' : ($p < 80 ? 'bg-warning' : 'bg-danger'));
    $svcBadge = function (string $state) {
        return match ($state) {
            'active' => 'bg-success',
            'activating', 'reloading' => 'bg-warning text-dark',
            'failed' => 'bg-danger',
            'inactive', 'deactivating' => 'bg-secondary',
            default => 'bg-light text-dark border',
        };
    };
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-hdd-rack-fill me-2 text-primary"></i>Server Status</h4>
        <small class="text-muted">
            {{ $host['hostname'] }} · {{ $host['os'] }} ({{ $host['arch'] }}) ·
            refreshed <span id="refreshed-at">just now</span>
        </small>
    </div>
    <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">
        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
    </button>
</div>

{{-- ── Host metric cards ─────────────────────────────────────── --}}
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-cpu-fill text-primary fs-5"></i><strong>CPU Load</strong>
                    <span class="badge bg-light text-dark border ms-auto">{{ $cpu['cores'] ?? '?' }} cores</span>
                </div>
                @if($cpu['load_1'] !== null)
                    <div class="fs-4 fw-bold mb-1">
                        <span id="cpu-load1">{{ $cpu['load_1'] }}</span>
                        <small class="text-muted fs-6">/ <span id="cpu-load5">{{ $cpu['load_5'] }}</span> / <span id="cpu-load15">{{ $cpu['load_15'] }}</span></small>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div id="cpu-bar" class="progress-bar {{ $barColor($cpu['load_percent']) }}"
                             style="width: {{ min(100, $cpu['load_percent'] ?? 0) }}%"></div>
                    </div>
                    <small class="text-muted">1 / 5 / 15 min averages</small>
                @else
                    <span class="text-muted">Unavailable on this host</span>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-memory text-success fs-5"></i><strong>Memory</strong>
                    <span id="mem-pct" class="badge bg-light text-dark border ms-auto">{{ $mem['available'] ? ($mem['percent'].'%') : '—' }}</span>
                </div>
                @if($mem['available'])
                    <div class="small mb-1" id="mem-text">{{ $mem['used_human'] }} of {{ $mem['total_human'] }}</div>
                    <div class="progress mb-2" style="height: 8px;">
                        <div id="mem-bar" class="progress-bar {{ $barColor($mem['percent']) }}" style="width: {{ $mem['percent'] }}%"></div>
                    </div>
                    @if(($mem['swap_total'] ?? 0) > 0)
                        <div class="small text-muted mb-1" id="swap-text">Swap: {{ $mem['swap_used_human'] }} of {{ $mem['swap_total_human'] }}</div>
                        <div class="progress" style="height: 4px;">
                            <div id="swap-bar" class="progress-bar {{ $barColor($mem['swap_percent']) }}" style="width: {{ $mem['swap_percent'] }}%"></div>
                        </div>
                    @endif
                @else
                    <span class="text-muted">Unavailable on this host</span>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-clock-history text-warning fs-5"></i><strong>Uptime</strong>
                </div>
                <div class="fs-5 fw-bold" id="host-uptime">{{ $host['uptime_human'] ?? '—' }}</div>
                <small class="text-muted">Server time: {{ $host['server_time'] }}</small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-stack text-info fs-5"></i><strong>Application</strong>
                    <span class="badge {{ $app['debug'] ? 'bg-danger' : 'bg-success' }} ms-auto">{{ $app['environment'] }}</span>
                </div>
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted">PHP</dt><dd class="col-7 mb-0">{{ $app['php_version'] }}</dd>
                    <dt class="col-5 text-muted">Laravel</dt><dd class="col-7 mb-0">{{ $app['laravel_version'] }}</dd>
                    <dt class="col-5 text-muted">MySQL</dt>
                    <dd class="col-7 mb-0">
                        @if($app['db']['connected'])
                            {{ Str::before($app['db']['version'], '-') }}
                            <span class="badge bg-light text-dark border">{{ $app['db']['size_human'] }}</span>
                        @else
                            <span class="text-danger">unreachable</span>
                        @endif
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- ── Disks ─────────────────────────────────────────────── --}}
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-transparent">
                <strong><i class="bi bi-device-hdd me-1"></i>Disks</strong>
            </div>
            <div class="card-body">
                @forelse($snapshot['disks'] as $disk)
                    <div class="mb-3" data-disk-mount="{{ $disk['mount'] }}">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="font-monospace">{{ $disk['mount'] }} <span class="text-muted">({{ $disk['filesystem'] }})</span></span>
                            <span class="disk-text">{{ $disk['used_human'] }} / {{ $disk['total_human'] }} · {{ $disk['free_human'] }} free</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar disk-bar {{ $barColor($disk['percent']) }}" style="width: {{ $disk['percent'] }}%"></div>
                        </div>
                    </div>
                @empty
                    <span class="text-muted">No disk information available.</span>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ── App health ───────────────────────────────────────── --}}
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-transparent">
                <strong><i class="bi bi-heart-pulse me-1"></i>Platform Health</strong>
            </div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted">Scheduler</dt>
                    <dd class="col-7">
                        <span id="scheduler-badge" class="badge {{ $app['scheduler_healthy'] ? 'bg-success' : 'bg-danger' }}">
                            {{ $app['scheduler_healthy'] ? 'Running' : 'Stalled' }}
                        </span>
                        <small class="text-muted" id="scheduler-last">
                            @if($app['scheduler_last_run'])
                                last tick {{ \Illuminate\Support\Carbon::parse($app['scheduler_last_run'])->diffForHumans() }}
                            @else
                                no heartbeat yet (deploy + wait a minute)
                            @endif
                        </small>
                    </dd>

                    <dt class="col-5 text-muted">Queue (database)</dt>
                    <dd class="col-7">
                        <span class="badge bg-light text-dark border"><span id="queue-pending">{{ $app['queue_pending'] ?? '—' }}</span> pending</span>
                        <span class="badge {{ ($app['queue_failed'] ?? 0) > 0 ? 'bg-danger' : 'bg-light text-dark border' }}" id="queue-failed-badge">
                            <span id="queue-failed">{{ $app['queue_failed'] ?? '—' }}</span> failed
                        </span>
                    </dd>

                    <dt class="col-5 text-muted">Database</dt>
                    <dd class="col-7">
                        @if($app['db']['connected'])
                            <span class="badge bg-success">Connected</span>
                            <small class="text-muted">{{ $app['db']['size_human'] }} on disk</small>
                        @else
                            <span class="badge bg-danger">Down</span>
                            <small class="text-danger">{{ Str::limit($app['db']['error'], 80) }}</small>
                        @endif
                    </dd>

                    <dt class="col-5 text-muted">storage/ writable</dt>
                    <dd class="col-7">
                        <span class="badge {{ $app['storage_writable'] ? 'bg-success' : 'bg-danger' }}">{{ $app['storage_writable'] ? 'Yes' : 'NO' }}</span>
                    </dd>

                    <dt class="col-5 text-muted">Drivers</dt>
                    <dd class="col-7 text-muted">
                        queue: <code>{{ $app['queue_driver'] }}</code> ·
                        cache: <code>{{ $app['cache_driver'] }}</code> ·
                        session: <code>{{ $app['session_driver'] }}</code>
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- ── systemd services ─────────────────────────────────── --}}
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-transparent">
                <strong><i class="bi bi-gear-wide-connected me-1"></i>System Services</strong>
                <small class="text-muted ms-2">systemd units (set SERVER_STATUS_SERVICES to adjust)</small>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0 small">
                    <tbody>
                    @forelse($snapshot['services'] as $svc)
                        <tr>
                            <td class="ps-3 font-monospace">{{ $svc['unit'] }}</td>
                            <td class="text-end pe-3">
                                <span class="badge svc-badge {{ $svcBadge($svc['state']) }}" data-unit="{{ $svc['unit'] }}">{{ $svc['state'] }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="text-center text-muted py-3">No services configured.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ── Docker containers ────────────────────────────────── --}}
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-transparent">
                <strong><i class="bi bi-box-seam me-1"></i>Docker Containers</strong>
                <small class="text-muted ms-2">Graylog, metrics, browser-portal sessions, …</small>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0 small">
                    <tbody>
                    @forelse($snapshot['docker'] as $c)
                        <tr>
                            <td class="ps-3">
                                <div class="font-monospace">{{ $c['name'] }}</div>
                                <small class="text-muted">{{ $c['image'] }}</small>
                            </td>
                            <td class="text-end pe-3">
                                <span class="badge docker-badge {{ $c['running'] ? 'bg-success' : 'bg-secondary' }}" data-container="{{ $c['name'] }}">{{ $c['status'] }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="text-center text-muted py-3">No containers (or docker unavailable to PHP).</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ── Database backups ──────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex align-items-center flex-wrap gap-2">
        <strong><i class="bi bi-database-down me-1"></i>Database Backups</strong>
        <small class="text-muted">mysqldump → gzip → Azure Blob ({{ config('db_backup.disk') }})</small>
        <div class="ms-auto d-flex align-items-center gap-2">
            @if($lastGoodBackup)
                <span class="badge bg-light text-dark border" title="{{ $lastGoodBackup->completed_at }}">
                    <i class="bi bi-check-circle me-1 text-success"></i>last OK {{ $lastGoodBackup->completed_at->diffForHumans() }}
                </span>
            @else
                <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>never backed up</span>
            @endif
            <span class="badge bg-light text-dark border">
                {{ $liveInAzureCount }} in Azure ·
                @php
                    $units = ['B','KB','MB','GB','TB']; $sz = (float) $liveInAzureBytes; $i = 0;
                    while ($sz >= 1024 && $i < 4) { $sz /= 1024; $i++; }
                @endphp
                {{ sprintf('%.1f %s', $sz, $units[$i]) }}
            </span>
            <span class="badge bg-light text-dark border" title="Set DB_BACKUP_RETENTION_DAYS to auto-prune">
                retention: {{ $retentionDays ? $retentionDays.' days' : 'forever' }}
            </span>
            @can('manage-server-status')
            <form method="POST" action="{{ route('admin.server-status.db-backups.run') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary" {{ $backupInFlight ? 'disabled' : '' }}>
                    <i class="bi bi-cloud-arrow-up me-1 {{ $backupInFlight ? 'spin' : '' }}"></i>
                    {{ $backupInFlight ? 'Backup in progress…' : 'Backup Now' }}
                </button>
            </form>
            @endcan
        </div>
    </div>
    <div class="card-body p-0">
        @if($backups->isEmpty())
            <div class="text-center text-muted py-4">
                No backups yet. The scheduler runs one daily at {{ config('db_backup.schedule_time') }}, or hit <strong>Backup Now</strong>.
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">File</th>
                        <th>Size</th>
                        <th>Status</th>
                        <th>Trigger</th>
                        <th>Started</th>
                        <th>Duration</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($backups as $b)
                    <tr>
                        <td class="ps-3">
                            <span class="font-monospace">{{ $b->filename ?? '(pending)' }}</span>
                            @if($b->error)
                                <div class="text-danger" title="{{ $b->error }}">
                                    <i class="bi bi-exclamation-triangle me-1"></i>{{ Str::limit($b->error, 90) }}
                                </div>
                            @endif
                        </td>
                        <td>{{ $b->humanSize() }}</td>
                        <td><span class="badge {{ $b->statusBadgeClass() }}">{{ ucfirst($b->status) }}</span></td>
                        <td>
                            @if($b->triggered_via === \App\Models\DatabaseBackup::VIA_MANUAL)
                                <i class="bi bi-person-fill me-1"></i>{{ $b->initiator?->name ?? 'manual' }}
                            @else
                                <i class="bi bi-clock me-1"></i>scheduled
                            @endif
                        </td>
                        <td class="text-muted" title="{{ $b->started_at }}">{{ ($b->started_at ?? $b->created_at)?->format('d M Y H:i') }}</td>
                        <td class="text-muted">{{ $b->durationSeconds() !== null ? $b->durationSeconds().'s' : '—' }}</td>
                        <td class="text-end pe-3">
                            @can('manage-server-status')
                                @if($b->isUploaded())
                                    <a href="{{ route('admin.server-status.db-backups.download', $b) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-download me-1"></i>Download
                                    </a>
                                    <form method="POST" action="{{ route('admin.server-status.db-backups.destroy', $b) }}" class="d-inline"
                                          onsubmit="return confirm('Delete {{ $b->filename }} from Azure? The history row is kept.');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete blob from Azure">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
(function () {
    const backupInFlight = @json($backupInFlight);

    // While a backup is pending/running, reload the whole page so the history
    // table tracks it. Otherwise, poll the metrics JSON and patch in place.
    if (backupInFlight) {
        setTimeout(() => location.reload(), 10000);
        return;
    }

    const metricsUrl = @json(route('admin.server-status.metrics'));
    let lastRefresh = Date.now();

    const barColor = p => p === null ? 'bg-secondary' : (p < 50 ? 'bg-success' : (p < 80 ? 'bg-warning' : 'bg-danger'));
    const svcBadge = s => ({
        active: 'bg-success', activating: 'bg-warning text-dark', reloading: 'bg-warning text-dark',
        failed: 'bg-danger', inactive: 'bg-secondary', deactivating: 'bg-secondary',
    }[s] || 'bg-light text-dark border');

    function setBar(id, percent) {
        const el = document.getElementById(id);
        if (!el || percent === null || percent === undefined) return;
        el.style.width = Math.min(100, percent) + '%';
        el.className = 'progress-bar ' + barColor(percent);
    }
    function setText(id, value) {
        const el = document.getElementById(id);
        if (el && value !== null && value !== undefined) el.textContent = value;
    }

    async function tick() {
        if (document.visibilityState !== 'visible') return;
        try {
            const r = await fetch(metricsUrl, { headers: { Accept: 'application/json' } });
            if (!r.ok) return;
            const s = await r.json();

            setText('cpu-load1', s.cpu.load_1); setText('cpu-load5', s.cpu.load_5); setText('cpu-load15', s.cpu.load_15);
            setBar('cpu-bar', s.cpu.load_percent);

            if (s.memory.available) {
                setText('mem-pct', s.memory.percent + '%');
                setText('mem-text', s.memory.used_human + ' of ' + s.memory.total_human);
                setBar('mem-bar', s.memory.percent);
                if (s.memory.swap_total > 0) {
                    setText('swap-text', 'Swap: ' + s.memory.swap_used_human + ' of ' + s.memory.swap_total_human);
                    setBar('swap-bar', s.memory.swap_percent);
                }
            }

            setText('host-uptime', s.host.uptime_human);

            (s.disks || []).forEach(d => {
                const wrap = document.querySelector(`[data-disk-mount="${CSS.escape(d.mount)}"]`);
                if (!wrap) return;
                const text = wrap.querySelector('.disk-text');
                if (text) text.textContent = `${d.used_human} / ${d.total_human} · ${d.free_human} free`;
                const bar = wrap.querySelector('.disk-bar');
                if (bar) { bar.style.width = d.percent + '%'; bar.className = 'progress-bar disk-bar ' + barColor(d.percent); }
            });

            (s.services || []).forEach(svc => {
                const el = document.querySelector(`.svc-badge[data-unit="${CSS.escape(svc.unit)}"]`);
                if (el) { el.textContent = svc.state; el.className = 'badge svc-badge ' + svcBadge(svc.state); }
            });

            (s.docker || []).forEach(c => {
                const el = document.querySelector(`.docker-badge[data-container="${CSS.escape(c.name)}"]`);
                if (el) { el.textContent = c.status; el.className = 'badge docker-badge ' + (c.running ? 'bg-success' : 'bg-secondary'); }
            });

            setText('queue-pending', s.app.queue_pending);
            setText('queue-failed', s.app.queue_failed);
            const qf = document.getElementById('queue-failed-badge');
            if (qf) qf.className = 'badge ' + (s.app.queue_failed > 0 ? 'bg-danger' : 'bg-light text-dark border');

            const sched = document.getElementById('scheduler-badge');
            if (sched) {
                sched.textContent = s.app.scheduler_healthy ? 'Running' : 'Stalled';
                sched.className = 'badge ' + (s.app.scheduler_healthy ? 'bg-success' : 'bg-danger');
            }

            lastRefresh = Date.now();
        } catch (e) { /* transient — next tick retries */ }
    }

    setInterval(tick, 15000);
    setInterval(() => {
        const secs = Math.round((Date.now() - lastRefresh) / 1000);
        setText('refreshed-at', secs < 5 ? 'just now' : secs + 's ago');
    }, 5000);
})();
</script>
@endpush

@push('styles')
<style>
@keyframes spin { to { transform: rotate(360deg); } }
.spin { display: inline-block; animation: spin 1s linear infinite; }
</style>
@endpush

@endsection
