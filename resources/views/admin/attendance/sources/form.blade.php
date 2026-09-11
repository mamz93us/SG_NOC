@extends('layouts.admin')

@section('title', $source->exists ? 'Edit Attendance Source' : 'Add Attendance Source')

@section('content')
@include('admin.attendance._tabs')

@php
    $locked = $source->exists && $source->hasPunches();
    $currentType = old('source_type', $source->source_type);
@endphp

<div class="mb-3">
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-database-gear me-2 text-primary"></i>{{ $source->exists ? 'Edit '.$source->name : 'Add attendance source' }}
    </h4>
    <small class="text-muted">
        One ZKTeco SQL Server database. Use a SQL login that can only <code>SELECT</code> from the punch table
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
                       placeholder="e.g. BioTime Egypt, ZKBio Access Cairo">
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold">Table</label>
                @if ($locked)
                    <input type="hidden" name="source_type" value="{{ $source->source_type }}">
                    <div class="form-control-plaintext">{{ $types[$source->source_type] ?? $source->source_type }}</div>
                    <div class="form-text">Fixed once a source holds punches.</div>
                @else
                    <select name="source_type" id="sourceType" class="form-select">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @selected($currentType === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        <code>iclock_transaction</code>: <code>emp_code</code>, <code>punch_time</code>, areas.
                        <code>acc_transaction</code>: <code>pin</code>, <code>create_time</code>, terminals only.
                    </div>
                @endif
            </div>

            <div class="col-md-6 type-field" data-type="acc_transaction" @if ($currentType !== 'acc_transaction') hidden @endif>
                <label class="form-label small fw-semibold">Punch time column</label>
                <select name="time_column" class="form-select">
                    <option value="create_time" @selected(old('time_column', $source->time_column ?: 'create_time') === 'create_time')>create_time — when the row was written</option>
                    <option value="event_time" @selected(old('time_column', $source->time_column) === 'event_time')>event_time — when the finger was scanned</option>
                </select>
                <div class="form-text">
                    For a terminal that is online they are the same. A terminal that uploads late keeps its real time only in
                    event_time. <strong>Test connection</strong> tells you which of the two the table has.
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold">Default branch <span class="text-muted fw-normal">(optional)</span></label>
                <select name="default_branch_id" class="form-select">
                    <option value="">— none —</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((string) old('default_branch_id', $source->default_branch_id) === (string) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                <div class="form-text">Used only when an employee code matches two people and neither the area nor the terminal has a branch.</div>
            </div>

            <div class="col-md-8">
                <label class="form-label small fw-semibold">SQL Server host</label>
                <input name="host" value="{{ old('host', $source->host) }}" class="form-control font-monospace" required maxlength="255"
                       placeholder="10.0.0.5 or zk-sql.internal">
                <div class="form-text">For a named instance with a dynamic port, give the instance a fixed port and use that.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Port</label>
                <input name="port" type="number" min="1" max="65535" value="{{ old('port', $source->port ?: 1433) }}" class="form-control font-monospace" required>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-semibold">Database</label>
                <input name="database" value="{{ old('database', $source->database) }}" class="form-control font-monospace" required maxlength="128"
                       placeholder="biotime / zkbiosecurity">
                @if ($locked)
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

            <div class="col-md-8 d-flex flex-column justify-content-center">
                <div class="form-check form-switch">
                    <input type="hidden" name="stores_utc" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="utc" name="stores_utc" value="1"
                           @checked(old('stores_utc', $source->stores_utc))>
                    <label class="form-check-label small" for="utc">
                        Times in this database are UTC
                        <span class="text-muted">— converted to the time zone on the right as they are read. Leave off when the
                        database keeps local time (BioTime normally does).</span>
                    </label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Convert UTC to</label>
                <select name="timezone" class="form-select">
                    <option value="">Server default ({{ config('app.timezone') }})</option>
                    @foreach ($timezones as $tz)
                        <option value="{{ $tz }}" @selected(old('timezone', $source->timezone) === $tz)>{{ $tz }}</option>
                    @endforeach
                </select>
            </div>
            @if ($locked)
                <div class="col-12">
                    <div class="form-text text-warning-emphasis">
                        Changing the time column or the UTC setting only affects punches read from now on. Re-read older ones with
                        <code>php artisan biotime:sync --source={{ $source->id }} --since=YYYY-MM-DD</code>.
                    </div>
                </div>
            @endif

            <div class="col-md-4">
                <label class="form-label small fw-semibold">Import punches from <span class="text-muted fw-normal">(first sync)</span></label>
                <input name="import_from" type="date" value="{{ old('import_from', $source->import_from?->toDateString()) }}" class="form-control">
                <div class="form-text">Blank = everything the table has. Only affects the very first sync.</div>
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

<script>
// Show the fields that belong to the chosen table.
(function () {
    var select = document.getElementById('sourceType');
    if (! select) {
        return;
    }
    function sync() {
        document.querySelectorAll('.type-field').forEach(function (el) {
            el.hidden = el.dataset.type !== select.value;
        });
    }
    select.addEventListener('change', sync);
    sync();
})();
</script>
@endsection
