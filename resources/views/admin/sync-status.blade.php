@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-arrow-repeat me-2 text-primary"></i>Sync Status</h4>
        <small class="text-muted">Last sync times, intervals, and manual triggers for all integrated services</small>
    </div>
</div>


@php
$serviceConfig = [
    'identity' => ['label' => 'Azure / Entra ID',           'icon' => 'bi-microsoft',     'color' => 'primary',  'intervalKey' => 'identity_sync_interval',  'desc' => 'Users, groups & licenses from Microsoft Graph'],
    'gdms'     => ['label' => 'GDMS (UCM Cloud)',           'icon' => 'bi-router-fill',   'color' => 'success',  'intervalKey' => 'gdms_sync_interval',       'desc' => 'SIP accounts & device registrations from GDMS'],
    'meraki'   => ['label' => 'Meraki Network',             'icon' => 'bi-hdd-network-fill','color' => 'warning', 'intervalKey' => 'meraki_polling_interval',  'desc' => 'Network devices and topology from Meraki API'],
];
@endphp

{{-- ── Service Cards ─────────────────────────────────────────── --}}
<div class="row g-4 mb-4">
@foreach($serviceConfig as $svcKey => $svc)
@php
    $s      = $status[$svcKey];
    $last   = $s['last'];
    $ok     = $s['lastOk'];
    $run    = $s['isRunning'];
    $interval = $intervals[$svcKey];

    if (!$last) {
        $statusClass = 'bg-secondary';
        $statusText  = 'Never synced';
    } elseif ($run) {
        $statusClass = 'bg-warning text-dark';
        $statusText  = 'Running…';
    } elseif ($last->status === 'completed') {
        $statusClass = 'bg-success';
        $statusText  = 'OK';
    } else {
        $statusClass = 'bg-danger';
        $statusText  = 'Failed';
    }
@endphp
<div class="col-md-4">
    <div class="card h-100 shadow-sm border-0">
        <div class="card-header d-flex align-items-center gap-2 bg-transparent">
            <i class="bi {{ $svc['icon'] }} text-{{ $svc['color'] }} fs-5"></i>
            <strong class="flex-grow-1">{{ $svc['label'] }}</strong>
            <span class="badge {{ $statusClass }}">{{ $statusText }}</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">{{ $svc['desc'] }}</p>

            <dl class="row small mb-3">
                <dt class="col-5 text-muted">Last sync</dt>
                <dd class="col-7">
                    @if($last)
                        <span title="{{ $last->started_at ?? $last->created_at }}">
                            {{ ($last->started_at ?? $last->created_at)->diffForHumans() }}
                        </span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </dd>

                <dt class="col-5 text-muted">Last OK</dt>
                <dd class="col-7">
                    @if($ok)
                        <span title="{{ $ok->completed_at }}">
                            {{ $ok->completed_at->diffForHumans() }}
                            @if($ok->records_synced !== null)
                                <span class="badge bg-light text-dark border ms-1">{{ number_format($ok->records_synced) }} records</span>
                            @endif
                        </span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </dd>

                <dt class="col-5 text-muted">Interval</dt>
                <dd class="col-7">
                    @if($interval >= 1440)
                        {{ round($interval / 1440) }}d
                    @elseif($interval >= 60)
                        {{ round($interval / 60, 1) }}h
                    @else
                        {{ $interval }}min
                    @endif
                    <small class="text-muted">({{ $interval }} min)</small>
                </dd>

                @if($last && $last->status === 'failed')
                <dt class="col-5 text-muted text-danger">Error</dt>
                <dd class="col-7 text-danger small">{{ Str::limit($last->error_message, 80) }}</dd>
                @endif
            </dl>

            <form method="POST" action="{{ route('admin.sync-status.trigger') }}">
                @csrf
                <input type="hidden" name="service" value="{{ $svcKey }}">
                <button type="submit" class="btn btn-sm btn-outline-{{ $svc['color'] }} w-100" {{ $run ? 'disabled' : '' }}>
                    <i class="bi bi-arrow-repeat me-1 {{ $run ? 'spin' : '' }}"></i>
                    {{ $run ? 'Running…' : 'Sync Now' }}
                </button>
            </form>
        </div>
    </div>
</div>
@endforeach
</div>

{{-- ── Interval Settings ─────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-clock me-1"></i>Sync Intervals</strong>
        <small class="text-muted ms-2">How often each service automatically syncs (via Laravel scheduler)</small>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.sync-status.intervals') }}">
            @csrf
            <div class="row g-3 align-items-end">
                @foreach($serviceConfig as $svcKey => $svc)
                <div class="col-md-4">
                    <label class="form-label fw-semibold">
                        <i class="bi {{ $svc['icon'] }} text-{{ $svc['color'] }} me-1"></i>{{ $svc['label'] }}
                    </label>
                    <div class="input-group">
                        <input type="number" name="{{ $svc['intervalKey'] }}"
                               class="form-control"
                               value="{{ $intervals[$svcKey] }}"
                               min="5" max="10080" step="1">
                        <span class="input-group-text">minutes</span>
                    </div>
                    <div class="form-text">
                        Common: 5 min, 30 min, 60 min, 720 min (12h)
                    </div>
                </div>
                @endforeach
                <div class="col-md-12 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save Intervals
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- ── Scheduler Setup Info ──────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-4 border-info">
    <div class="card-header bg-transparent d-flex align-items-center gap-2">
        <i class="bi bi-terminal-fill text-info"></i>
        <strong>Auto-Scheduler Setup (Cron)</strong>
        <span id="cronStatus" class="badge bg-secondary ms-auto">Checking…</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-2">
            For syncs to run automatically, add this single <strong>cron entry</strong> on your server (runs every minute — Laravel handles the rest):
        </p>
        <div class="bg-dark text-light rounded p-3 font-monospace small d-flex align-items-center gap-2">
            <code id="cronLine">* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1</code>
            <button class="btn btn-sm btn-outline-light ms-auto flex-shrink-0" onclick="copyCron()" title="Copy">
                <i class="bi bi-clipboard" id="copyIcon"></i>
            </button>
        </div>
        <p class="text-muted small mt-2 mb-0">
            <strong>To add it:</strong> Run <code>crontab -e</code> on the server and paste the line above.
            Or run: <code>( crontab -l 2>/dev/null; echo "* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1" ) | crontab -</code>
        </p>
    </div>
</div>

{{-- ── Recent Sync History ───────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-clock-history me-1"></i>Recent Sync Activity</strong>
        <small class="text-muted ms-2">(last 20 runs across all services)</small>
    </div>
    <div class="card-body p-0">
        @if($history->isEmpty())
            <div class="text-center text-muted py-4">No sync history yet. Run a sync manually or set up the cron.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Service</th>
                        <th>Started</th>
                        <th>Status</th>
                        <th class="text-center">Records</th>
                        <th>Duration</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($history as $h)
                @php
                    $sLabel = $serviceConfig[$h->service]['label'] ?? $h->service;
                    $sIcon  = $serviceConfig[$h->service]['icon']  ?? 'bi-arrow-repeat';
                    $sColor = $serviceConfig[$h->service]['color'] ?? 'secondary';
                @endphp
                <tr>
                    <td class="ps-3">
                        <i class="bi {{ $sIcon }} text-{{ $sColor }} me-1"></i>
                        <strong>{{ $sLabel }}</strong>
                    </td>
                    <td class="text-muted">{{ ($h->started_at ?? $h->created_at)?->format('d M Y H:i:s') }}</td>
                    <td><span class="badge {{ $h->statusBadgeClass() }}">{{ ucfirst($h->status) }}</span></td>
                    <td class="text-center">{{ $h->records_synced !== null ? number_format($h->records_synced) : '—' }}</td>
                    <td class="text-muted">{{ $h->durationSeconds() !== null ? $h->durationSeconds() . 's' : '—' }}</td>
                    <td class="text-muted">
                        @if($h->error_message)
                            <span class="text-danger" title="{{ $h->error_message }}">
                                <i class="bi bi-exclamation-triangle me-1"></i>{{ Str::limit($h->error_message, 60) }}
                            </span>
                        @else
                            —
                        @endif
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
function copyCron() {
    const text = document.getElementById('cronLine').textContent.trim();
    navigator.clipboard.writeText(text).then(() => {
        const icon = document.getElementById('copyIcon');
        icon.className = 'bi bi-clipboard-check';
        setTimeout(() => { icon.className = 'bi bi-clipboard'; }, 2000);
    });
}

// Auto-refresh if any service is running
@php $anyRunning = collect($status)->contains(fn($s) => $s['isRunning']); @endphp
@if($anyRunning || session('info'))
setTimeout(() => location.reload(), 5000);
@endif
</script>
@endpush

@push('styles')
<style>
@keyframes spin { to { transform: rotate(360deg); } }
.spin { display: inline-block; animation: spin 1s linear infinite; }
</style>
@endpush

@endsection
