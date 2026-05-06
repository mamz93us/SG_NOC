@extends('layouts.admin')
@section('content')

@php $editing = $rule->exists; @endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-bell{{ $editing ? '-fill' : '' }} me-2 text-warning"></i>
            {{ $editing ? 'Edit' : 'New' }} Syslog Alert Rule
        </h4>
        <small class="text-muted">Match pattern → create NocEvent for NOC notifications.</small>
    </div>
    <a href="{{ route('admin.syslog.rules.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

@if($errors->any())
<div class="alert alert-danger">
    <ul class="mb-0">
        @foreach($errors->all() as $err) <li>{{ $err }}</li> @endforeach
    </ul>
</div>
@endif

<form method="POST" action="{{ $editing ? route('admin.syslog.rules.update', $rule) : route('admin.syslog.rules.store') }}">
    @csrf
    @if($editing) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-md-7">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header py-2"><strong>Rule</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required maxlength="191"
                               value="{{ old('name', $rule->name) }}" placeholder="Sophos auth failures">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="enabled" id="enabled" value="1"
                               {{ old('enabled', $rule->enabled) ? 'checked' : '' }}>
                        <label class="form-check-label" for="enabled">Enabled</label>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header py-2"><strong>Match filters</strong> <small class="text-muted">— all populated filters must match</small></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Max severity (lower = more severe)</label>
                            <select name="severity_max" class="form-select">
                                @foreach(\App\Models\SyslogMessage::SEVERITIES as $val => $label)
                                <option value="{{ $val }}" {{ (string) old('severity_max', $rule->severity_max) === (string) $val ? 'selected' : '' }}>{{ $val }} — {{ $label }} & worse</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Source type</label>
                            <select name="source_type" class="form-select">
                                <option value="">Any</option>
                                @foreach(['sophos','cisco','ucm','printer','vps','unknown'] as $st)
                                <option value="{{ $st }}" {{ old('source_type', $rule->source_type) === $st ? 'selected' : '' }}>{{ ucfirst($st) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Host contains <small class="text-muted">(case-insensitive substring)</small></label>
                            <input type="text" name="host_contains" class="form-control"
                                   value="{{ old('host_contains', $rule->host_contains) }}" placeholder="JED-FW">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Message regex <small class="text-muted">(PCRE; auto-wrapped if no delimiters)</small></label>
                            <input type="text" name="message_regex" class="form-control font-monospace"
                                   value="{{ old('message_regex', $rule->message_regex) }}" placeholder="authentication failure|denied">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header py-2"><strong>When matched, raise NocEvent</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Event severity</label>
                        <select name="event_severity" class="form-select">
                            @foreach(['info','warning','critical'] as $s)
                            <option value="{{ $s }}" {{ old('event_severity', $rule->event_severity) === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Module</label>
                        <input type="text" name="event_module" class="form-control"
                               value="{{ old('event_module', $rule->event_module ?: 'syslog') }}" maxlength="32">
                        <div class="form-text">Used for NocEvent grouping; default <code>syslog</code> is fine.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cooldown (minutes)</label>
                        <input type="number" name="cooldown_minutes" class="form-control"
                               min="1" max="1440"
                               value="{{ old('cooldown_minutes', $rule->cooldown_minutes ?: 15) }}">
                        <div class="form-text">Identical (rule, host) pairs collapse into one open event during this window.</div>
                    </div>
                </div>
            </div>

            <div class="card border-0 bg-light">
                <div class="card-body small text-muted">
                    <strong>Tip:</strong> create a focused rule per signature
                    (auth failures, link down, certificate expiry…). Broad
                    rules tend to either flap noisily or get muted entirely.
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary">{{ $editing ? 'Save changes' : 'Create rule' }}</button>
        <a href="{{ route('admin.syslog.rules.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection
