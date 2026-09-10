@extends('layouts.admin')

@section('title', 'BioTime Sources')

@section('content')
@include('admin.attendance._tabs')

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-database-gear me-2 text-primary"></i>BioTime Sources</h4>
        <small class="text-muted">
            Every ZKTeco BioTime SQL Server database punches are read from. Read-only — the NOC never writes to BioTime,
            and reads only <code>iclock_transaction</code>.
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
            <strong>{{ $t['source'] }}</strong> — connected in {{ $t['latency_ms'] }} ms.
            Latest <code>iclock_transaction.id</code> is {{ number_format($t['max_id']) }}.
        </div>
        <div class="card-body">
            <div class="small mb-2">
                <span class="text-muted">Areas seen recently:</span>
                @forelse ($t['areas'] as $area)
                    <span class="badge bg-secondary-subtle text-body border me-1">{{ $area }}</span>
                @empty
                    <span class="text-muted">none</span>
                @endforelse
            </div>
            <div class="table-responsive">
                <table class="table table-sm small mb-0">
                    <thead class="table-light">
                        <tr>
                            @foreach ($columns as $column)
                                <th>{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($t['sample'] as $row)
                            <tr>
                                @foreach ($columns as $column)
                                    <td class="font-monospace">{{ $row[$column] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($columns) }}" class="text-muted">The table is empty.</td></tr>
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
                            <div class="small text-muted font-monospace">{{ $source->host }}:{{ $source->port }}</div>
                            @if ($source->defaultBranch)
                                <div class="small text-muted">Default branch: {{ $source->defaultBranch->name }}</div>
                            @endif
                        </td>
                        <td class="small">
                            <div class="font-monospace">{{ $source->database }}</div>
                            <div class="text-muted">as {{ $source->username }}</div>
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
                                    <div class="text-muted">{{ number_format($source->last_sync_rows) }} punch(es) last run</div>
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
                        <td class="text-end font-monospace small">{{ number_format($source->last_id) }}</td>
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
                            No BioTime databases yet — press <strong>Add source</strong>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="small text-muted mt-3 mb-0">
    New punches are pulled every 5 minutes by <code>biotime:sync</code>, by <code>iclock_transaction.id</code> — so punches a
    terminal uploads late are still picked up. "Sync now" reads at most 20,000 rows; a first backfill continues on the schedule.
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
