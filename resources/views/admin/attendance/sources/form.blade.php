@extends('layouts.admin')

@section('title', $source->exists ? 'Edit BioTime Source' : 'Add BioTime Source')

@section('content')
@include('admin.attendance._tabs')

<div class="mb-3">
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-database-gear me-2 text-primary"></i>{{ $source->exists ? 'Edit '.$source->name : 'Add BioTime source' }}
    </h4>
    <small class="text-muted">
        One BioTime SQL Server database. Use a SQL login that can only <code>SELECT</code> from <code>iclock_transaction</code>
        — see <code>BIOTIME_ATTENDANCE_SETUP.md</code> for the exact grant.
    </small>
</div>

<form method="POST"
      action="{{ $source->exists ? route('admin.attendance.sources.update', $source) : route('admin.attendance.sources.store') }}"
      class="card shadow-sm border-0" style="max-width: 860px">
    @csrf
    @if ($source->exists)
        @method('PUT')
    @endif

    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label small fw-semibold">Name</label>
                <input name="name" value="{{ old('name', $source->name) }}" class="form-control" required maxlength="100"
                       placeholder="e.g. BioTime Egypt">
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold">Default branch <span class="text-muted fw-normal">(optional)</span></label>
                <select name="default_branch_id" class="form-select">
                    <option value="">— none —</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((string) old('default_branch_id', $source->default_branch_id) === (string) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                <div class="form-text">Used only when an employee code matches two people and the punch's area has no branch.</div>
            </div>

            <div class="col-md-8">
                <label class="form-label small fw-semibold">SQL Server host</label>
                <input name="host" value="{{ old('host', $source->host) }}" class="form-control font-monospace" required maxlength="255"
                       placeholder="10.0.0.5 or biotime-sql.internal">
                <div class="form-text">For a named instance with a dynamic port, give the instance a fixed port and use that.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Port</label>
                <input name="port" type="number" min="1" max="65535" value="{{ old('port', $source->port ?: 1433) }}" class="form-control font-monospace" required>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-semibold">Database</label>
                <input name="database" value="{{ old('database', $source->database) }}" class="form-control font-monospace" required maxlength="128"
                       placeholder="biotime">
                @if ($source->exists && $source->last_id > 0)
                    <div class="form-text text-warning-emphasis">This source already holds punches — a different database needs a new source.</div>
                @endif
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold">SQL login</label>
                <input name="username" value="{{ old('username', $source->username) }}" class="form-control font-monospace" required maxlength="128"
                       autocomplete="off">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Password</label>
                <input name="password" type="password" class="form-control" maxlength="255" autocomplete="new-password"
                       {{ $source->exists ? '' : 'required' }}
                       placeholder="{{ $source->exists ? '•••••• (leave blank to keep current)' : '' }}">
                <div class="form-text">Stored encrypted at rest.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-semibold">Import punches from <span class="text-muted fw-normal">(first sync)</span></label>
                <input name="import_from" type="date" value="{{ old('import_from', $source->import_from?->toDateString()) }}" class="form-control">
                <div class="form-text">Blank = everything BioTime has. Only affects the very first sync.</div>
            </div>
            <div class="col-md-8 d-flex flex-column justify-content-center gap-2">
                <div class="form-check form-switch">
                    <input type="hidden" name="trust_server_certificate" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="trust" name="trust_server_certificate" value="1"
                           @checked(old('trust_server_certificate', $source->trust_server_certificate))>
                    <label class="form-check-label small" for="trust">
                        Trust the server's certificate <span class="text-muted">— needed for SQL Server's default self-signed cert</span>
                    </label>
                </div>
                <div class="form-check form-switch">
                    <input type="hidden" name="enabled" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1"
                           @checked(old('enabled', $source->enabled))>
                    <label class="form-check-label small" for="enabled">Enabled <span class="text-muted">— synced every 5 minutes</span></label>
                </div>
            </div>
        </div>
    </div>

    <div class="card-footer bg-transparent d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i>Save
        </button>
        <button type="submit" name="then_test" value="1" class="btn btn-outline-success">
            <i class="bi bi-plug me-1"></i>Save &amp; test connection
        </button>
        <a href="{{ route('admin.attendance.sources.index') }}" class="btn btn-link text-muted ms-auto">Cancel</a>
    </div>
</form>
@endsection
