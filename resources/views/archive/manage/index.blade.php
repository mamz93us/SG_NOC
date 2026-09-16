@extends('layouts.archive')

@section('title', 'Manage')

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('archive.index') }}" class="arc-muted text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Archives
        </a>
        <span class="arc-muted">/</span>
        <span class="fw-semibold">Manage</span>

        <div class="ms-auto d-flex gap-2">
            <a href="{{ route('archive.review') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-check2-square"></i> Review proposals
            </a>
            <a href="{{ route('archive.manage.scan') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer"></i> Scan destinations
            </a>
            <a href="{{ route('archive.manage.ai') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-stars"></i> AI
            </a>
            <a href="{{ route('archive.manage.transfer') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-cloud-arrow-up"></i> Transfer to Azure
            </a>
        </div>
    </div>

    <div class="row g-3">
        {{-- ── The ArcMate connection ─────────────────────────────── --}}
        <div class="col-lg-5">
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-3">ArcMate connection</h2>

                <form method="POST" action="{{ route('archive.manage.source.save') }}">
                    @csrf
                    <div class="row g-2">
                        <div class="col-8">
                            <label class="form-label small arc-muted mb-1">SQL Server host</label>
                            <input class="form-control form-control-sm" name="host" value="{{ old('host', $source->host) }}" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small arc-muted mb-1">Port</label>
                            <input class="form-control form-control-sm" name="port" value="{{ old('port', $source->port) }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label small arc-muted mb-1">Read-only login</label>
                            <input class="form-control form-control-sm" name="username" value="{{ old('username', $source->username) }}" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small arc-muted mb-1">Password</label>
                            {{-- Write-only. Never rendered back, and left blank means "keep
                                 the one you have" so saving another setting cannot wipe it. --}}
                            <input class="form-control form-control-sm" type="password" name="password"
                                   autocomplete="new-password"
                                   placeholder="{{ $source->password ? 'Unchanged' : 'Required' }}">
                            <div class="form-text small">
                                Use a login with <code>db_datareader</code> only — never ArcMate's own <code>sa</code>.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small arc-muted mb-1">File share mounted at</label>
                            <input class="form-control form-control-sm" name="mount_path" value="{{ old('mount_path', $source->mount_path) }}">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="trust_server_certificate" value="1"
                                       id="trustCert" @checked($source->trust_server_certificate)>
                                <label class="form-check-label small" for="trustCert">
                                    Trust the server certificate (it is self-signed)
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-brand btn-sm px-3">Save</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('archive.manage.source.test') }}" class="mt-2">
                    @csrf
                    <button class="btn btn-outline-secondary btn-sm w-100">Test connection</button>
                </form>
            </div>

            {{-- ── What the test found ────────────────────────────── --}}
            <div class="arc-card p-3">
                <h2 class="h6 mb-3">Status</h2>

                <ul class="list-unstyled mb-0 small">
                    <li class="mb-2">
                        @if ($driverAvailable)
                            <i class="bi bi-check-circle" style="color:var(--green)"></i> SQL Server driver installed
                        @else
                            <i class="bi bi-x-circle text-danger"></i> The PHP SQL Server driver (pdo_sqlsrv) is missing
                        @endif
                    </li>
                    <li class="mb-2">
                        @if ($mounted)
                            <i class="bi bi-check-circle" style="color:var(--green)"></i> Share mounted at <code>{{ $source->mountPath() }}</code>
                        @else
                            <i class="bi bi-x-circle text-danger"></i> No share at <code>{{ $source->mountPath() }}</code>
                        @endif
                    </li>

                    @if ($testResult)
                        <li class="mb-2">
                            @if ($testResult['sql']['ok'])
                                <i class="bi bi-check-circle" style="color:var(--green)"></i>
                                Connected — {{ count($testResult['sql']['databases']) }} ArcMate database(s) visible
                            @else
                                <i class="bi bi-x-circle text-danger"></i> {{ $testResult['sql']['error'] }}
                            @endif
                            <div class="arc-muted">Tested {{ $testResult['at'] }}</div>
                        </li>

                        @foreach ($testResult['files'] as $check)
                            <li class="mb-1">
                                @if ($check['exists'])
                                    <i class="bi bi-check-circle" style="color:var(--green)"></i>
                                @else
                                    <i class="bi bi-exclamation-triangle" style="color:var(--amber)"></i>
                                @endif
                                <span class="arc-muted">{{ $check['archive'] }}:</span>
                                <span class="text-break">{{ $check['path'] }}</span>
                            </li>
                        @endforeach
                    @endif
                </ul>
            </div>
        </div>

        {{-- ── Projects on the share ──────────────────────────────── --}}
        <div class="col-lg-7">
            <div class="arc-card p-3 mb-3">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h6 mb-0">Projects on the ArcMate share</h2>
                    <form method="POST" action="{{ route('archive.manage.tasks.store') }}" class="m-0">
                        @csrf
                        <input type="hidden" name="type" value="rescan_source">
                        <button class="btn btn-outline-secondary btn-sm">Rescan</button>
                    </form>
                </div>

                @if (! $mounted)
                    <p class="arc-muted small mb-0">
                        The share is not mounted, so nothing can be read. Mount it read-only first
                        (<code>deployment/archive-portal/mount-arcmate.sh</code>).
                    </p>
                @elseif (empty($projects))
                    <p class="arc-muted small mb-0">No ArcMate projects found on the share.</p>
                @else
                    <div class="table-responsive">
                        <table class="table arc-table mb-0">
                            <thead>
                                <tr><th>Project</th><th>Database</th><th>Fields</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($projects as $project)
                                    @php($existing = $archives->firstWhere('arcmate_folder', $project['folder']))
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $project['name'] ?: $project['folder'] }}</div>
                                            <div class="arc-muted small">{{ $project['folder'] }}</div>
                                            @if ($project['issue'])
                                                <div class="small" style="color:var(--amber)">{{ $project['issue'] }}</div>
                                            @endif
                                        </td>
                                        <td class="arc-muted small">{{ $project['database'] ?: '—' }}</td>
                                        <td class="arc-muted small">{{ count($project['fields']) }}</td>
                                        <td class="text-end">
                                            @if ($existing)
                                                <a class="btn btn-sm btn-outline-secondary"
                                                   href="{{ route('archive.manage.archive', $existing) }}">Set up</a>
                                            @else
                                                <form method="POST" action="{{ route('archive.manage.enable') }}" class="m-0">
                                                    @csrf
                                                    <input type="hidden" name="folder" value="{{ $project['folder'] }}">
                                                    <button class="btn btn-sm btn-brand">Mirror it</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Archives already set up ────────────────────────── --}}
            <div class="arc-card p-3">
                <h2 class="h6 mb-3">Archives</h2>

                @if ($archives->isEmpty())
                    <p class="arc-muted small mb-0">None yet. Mirror a project from the list above.</p>
                @else
                    <div class="table-responsive">
                        <table class="table arc-table mb-0">
                            <thead>
                                <tr><th>Archive</th><th>Mode</th><th>Documents</th><th>People</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($archives as $archive)
                                    <tr>
                                        <td>
                                            <a href="{{ route('archive.manage.archive', $archive) }}"
                                               class="fw-semibold text-decoration-none">{{ $archive->displayName() }}</a>
                                            @unless ($archive->readable)
                                                <span class="badge bg-warning-subtle text-warning-emphasis ms-1">encrypted</span>
                                            @endunless
                                        </td>
                                        <td class="arc-muted small">{{ \App\Models\Archive\Archive::MODES[$archive->mode] ?? $archive->mode }}</td>
                                        <td class="arc-muted small">{{ number_format($archive->document_count) }}</td>
                                        <td class="arc-muted small">{{ $archive->members_count }}</td>
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
