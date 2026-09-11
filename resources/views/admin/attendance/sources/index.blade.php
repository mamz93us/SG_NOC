@extends('layouts.admin')

@section('title', 'Attendance Sources')

@section('content')
@include('admin.attendance._tabs')

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-database-gear me-2 text-primary"></i>Attendance Sources</h4>
        <small class="text-muted">
            Every ZKTeco SQL Server database punches are read from — BioTime attendance (<code>iclock_transaction</code>)
            or ZKBio access control (<code>acc_transaction</code>). Read-only: the NOC never writes to them.
        </small>
    </div>
    <a href="{{ route('admin.attendance.sources.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Add source
    </a>
</div>

@unless ($driverAvailable)
    <div class="alert alert-danger d-flex gap-2">
        <i class="bi bi-exclamation-octagon-fill fs-5"></i>
        <div class="small">
            The PHP SQL Server driver (<code>pdo_sqlsrv</code>) is not installed on this server, so no source can connect.
            Run <code>sudo bash deployment/biotime/install-sqlsrv.sh</code> on the NOC — see <code>BIOTIME_ATTENDANCE_SETUP.md</code>.
        </div>
    </div>
@endunless

@if (session('test_result'))
    @php $t = session('test_result'); @endphp
    <div class="card border-success shadow-sm mb-4">
        <div class="card-header bg-success-subtle">
            <i class="bi bi-check-circle-fill text-success me-1"></i>
            <strong>{{ $t['source'] }}</strong> — connected to <code>{{ $t['table'] }}</code> in {{ $t['latency_ms'] }} ms.
            {{ $t['watermark'] }}.
        </div>
        <div class="card-body">
            @if (! empty($t['clock']))
                <div class="small mb-2">
                    <span class="text-muted">SQL Server clock:</span>
                    <span class="font-monospace">{{ $t['clock']['local'] }}</span> local,
                    <span class="font-monospace">{{ $t['clock']['utc'] }}</span> UTC.
                    @if (! empty($t['newest']))
                        <span class="text-muted">Newest row:</span> <span class="font-monospace">{{ $t['newest'] }}</span>.
                    @endif
                    <div class="text-muted">
                        If the newest row is close to the UTC clock rather than the local one, the table stores UTC —
                        switch on <em>Times in this database are UTC</em> for this source.
                    </div>
                </div>
            @endif
            @foreach ($t['notes'] ?? [] as $note)
                <div class="small text-warning-emphasis mb-2"><i class="bi bi-info-circle me-1"></i>{{ $note }}</div>
            @endforeach
            <div class="small mb-2">
                <span class="text-muted">{{ $t['locations_label'] }}:</span>
                @forelse ($t['locations'] as $location)
                    <span class="badge bg-secondary-subtle text-body border me-1">{{ $location }}</span>
                @empty
                    <span class="text-muted">none</span>
                @endforelse
            </div>
            <div class="table-responsive">
                <table class="table table-sm small mb-0">
                    <thead class="table-light">
                        <tr>
                            @foreach ($t['columns'] as $column)
                                <th>{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($t['sample'] as $row)
                            <tr>
                                @foreach ($t['columns'] as $column)
                                    <td class="font-monospace">{{ $row[$column] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($t['columns']) }}" class="text-muted">The table is empty.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

@if (session('test_error'))
    <div class="alert alert-danger">
        <i class="bi bi-plug-fill me-1"></i>
        <strong>{{ session('test_error_source') }}</strong> — connection failed:
        <pre class="mb-0 mt-2 small" style="white-space:pre-wrap">{{ session('test_error') }}</pre>
    </div>
@endif

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Source</th>
                    <th>Database</th>
                    <th>Last sync</th>
                    <th class="text-end">Watermark</th>
                    <th class="text-end">Codes</th>
                    <th style="width:260px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sources as $source)
                    <tr>
                        <td>
                            <div class="fw-semibold">
                                {{ $source->name }}
                                @unless ($source->enabled)
                                    <span class="badge bg-secondary ms-1">disabled</span>
                                @endunless
                            </div>
                            <div class="small">
                                <span class="badge {{ $source->isAccessControl() ? 'bg-info-subtle text-info-emphasis' : 'bg-primary-subtle text-primary-emphasis' }} border">
                                    {{ $source->isAccessControl() ? 'Access control' : 'BioTime' }}
                                </span>
                                <span class="text-muted font-monospace">{{ $source->host }}:{{ $source->port }}</span>
                            </div>
                            @if ($source->defaultBranch)
                                <div class="small text-muted">Default branch: {{ $source->defaultBranch->name }}</div>
                            @endif
                        </td>
                        <td class="small">
                            <div class="font-monospace">{{ $source->database }}.{{ $source->source_type }}</div>
                            <div class="text-muted">
                                as {{ $source->username }}
                                @if ($source->isAccessControl())
                                    · time from <code>{{ $source->time_column ?: 'create_time' }}</code>
                                @endif
                                @if ($source->stores_utc)
                                    · UTC → {{ $source->timezone ?: config('app.timezone') }}
                                @endif
                            </div>
                        </td>
                        <td class="small">
                            @if ($source->last_sync_at)
                                @if ($source->last_sync_status === 'ok')
                                    <span class="badge bg-success">OK</span>
                                @else
                                    <span class="badge bg-danger">Error</span>
                                    @if ($source->consecutive_failures > 1)
                                        <span class="text-danger">{{ $source->consecutive_failures }}× in a row</span>
                                    @endif
                                @endif
                                <span class="text-muted">{{ $source->last_sync_at->diffForHumans() }}</span>
                                @if ($source->last_sync_status === 'ok')
                                    <div class="text-muted">{{ number_format($source->last_sync_rows) }} row(s) last run</div>
                                @endif
                                @if ($source->last_sync_error)
                                    <div class="text-danger text-break">{{ \Illuminate\Support\Str::limit($source->last_sync_error, 200) }}</div>
                                @endif
                            @else
                                <span class="text-muted">Never synced</span>
                            @endif
                            @if ($source->last_test_at)
                                <div class="text-muted">Tested {{ $source->last_test_at->diffForHumans() }}: {{ \Illuminate\Support\Str::limit($source->last_test_result, 120) }}</div>
                            @endif
                        </td>
                        <td class="text-end font-monospace small">{{ $source->watermarkLabel() }}</td>
                        <td class="text-end small">{{ number_format($source->employees_count) }}</td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <form method="POST" action="{{ route('admin.attendance.sources.test', $source) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-success js-busy" data-busy="Testing…">
                                        <i class="bi bi-plug me-1"></i>Test
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.attendance.sources.sync', $source) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-primary js-busy" data-busy="Syncing…">
                                        <i class="bi bi-arrow-repeat me-1"></i>Sync now
                                    </button>
                                </form>
                                <a href="{{ route('admin.attendance.sources.edit', $source) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-database fs-3 d-block mb-2"></i>
                            No databases yet — press <strong>Add source</strong>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="small text-muted mt-3 mb-0">
    New punches are pulled every 5 minutes by <code>biotime:sync</code> — BioTime by id, so punches a terminal uploads late
    are still picked up; access control by time and id. "Sync now" reads at most 20,000 rows; a first backfill continues
    on the schedule.
</p>

<script>
// Test and sync run inline and take a few seconds; stop a second click starting another.
document.querySelectorAll('.js-busy').forEach(function (btn) {
    btn.closest('form').addEventListener('submit', function () {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + btn.dataset.busy;
    });
});
</script>
@endsection
