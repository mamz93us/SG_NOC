@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex align-items-center mb-4">
        <a href="{{ route('admin.alert-rules.index') }}" class="btn btn-sm btn-outline-secondary me-3">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <h4 class="mb-0 fw-bold">
                <i class="bi bi-shield-exclamation me-2"></i>
                {{ $rule->exists ? 'Edit Alert Rule' : 'New Alert Rule' }}
            </h4>
            <small class="text-muted">Configure conditions and notification channels.</small>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Please fix the following errors:</strong>
            <ul class="mb-0 mt-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <form method="POST"
          action="{{ $rule->exists ? route('admin.alert-rules.update', $rule) : route('admin.alert-rules.store') }}">
        @csrf
        @if($rule->exists)
            @method('PUT')
        @endif

        <div class="row g-4">

            {{-- Left column --}}
            <div class="col-lg-8">

                {{-- Basic Info --}}
                <div class="card shadow-sm mb-4">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-info-circle me-2"></i>Basic Information
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Rule Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $rule->name) }}"
                                   class="form-control @error('name') is-invalid @enderror"
                                   placeholder="e.g. Low Toner Warning" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" rows="2"
                                      class="form-control @error('description') is-invalid @enderror"
                                      placeholder="Optional description of what this rule monitors">{{ old('description', $rule->description) }}</textarea>
                            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Severity <span class="text-danger">*</span></label>
                                <select name="severity" class="form-select @error('severity') is-invalid @enderror" required>
                                    <option value="warning" {{ old('severity', $rule->severity) === 'warning' ? 'selected' : '' }}>Warning</option>
                                    <option value="critical" {{ old('severity', $rule->severity) === 'critical' ? 'selected' : '' }}>Critical</option>
                                </select>
                                @error('severity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Target Type <span class="text-danger">*</span></label>
                                <select name="target_type" class="form-select @error('target_type') is-invalid @enderror" required>
                                    <option value="sensor"  {{ old('target_type', $rule->target_type) === 'sensor'  ? 'selected' : '' }}>Sensor</option>
                                    <option value="printer" {{ old('target_type', $rule->target_type) === 'printer' ? 'selected' : '' }}>Printer</option>
                                    <option value="host"    {{ old('target_type', $rule->target_type) === 'host'    ? 'selected' : '' }}>Host</option>
                                </select>
                                @error('target_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Condition --}}
                <div class="card shadow-sm mb-4">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-funnel me-2"></i>Alert Condition
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Sensor Class</label>
                            <input type="text" name="sensor_class"
                                   value="{{ old('sensor_class', $rule->sensor_class) }}"
                                   class="form-control @error('sensor_class') is-invalid @enderror"
                                   placeholder="e.g. toner, temperature, traffic, memory">
                            <div class="form-text">Leave blank to apply to all sensor classes of the selected target type.</div>
                            @error('sensor_class')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Operator <span class="text-danger">*</span></label>
                                <select name="operator" class="form-select @error('operator') is-invalid @enderror" required>
                                    @foreach(['<=' => '≤ Less than or equal', '>=' => '≥ Greater than or equal', '<' => '< Less than', '>' => '> Greater than', '==' => '= Equal to', '!=' => '≠ Not equal to'] as $op => $label)
                                        <option value="{{ $op }}" {{ old('operator', $rule->operator) === $op ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('operator')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Threshold Value <span class="text-danger">*</span></label>
                                <input type="number" step="any" name="threshold_value"
                                       value="{{ old('threshold_value', $rule->threshold_value) }}"
                                       class="form-control @error('threshold_value') is-invalid @enderror"
                                       placeholder="e.g. 10" required>
                                @error('threshold_value')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-info py-2 mb-0 small">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Alert fires when: <strong>value {{ old('operator', $rule->operator ?? '<=') }} {{ old('threshold_value', $rule->threshold_value ?? '?') }}</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Timing --}}
                <div class="card shadow-sm mb-4">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-clock me-2"></i>Timing Controls
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Delay Seconds</label>
                                <input type="number" name="delay_seconds" min="0" max="86400"
                                       value="{{ old('delay_seconds', $rule->delay_seconds ?? 300) }}"
                                       class="form-control @error('delay_seconds') is-invalid @enderror">
                                <div class="form-text">How long the condition must persist before firing (default: 300s / 5 min).</div>
                                @error('delay_seconds')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Interval Seconds</label>
                                <input type="number" name="interval_seconds" min="60" max="86400"
                                       value="{{ old('interval_seconds', $rule->interval_seconds ?? 3600) }}"
                                       class="form-control @error('interval_seconds') is-invalid @enderror">
                                <div class="form-text">Minimum time between repeated notifications (default: 3600s / 1 hour).</div>
                                @error('interval_seconds')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="mt-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="recovery_alert" id="recovery_alert"
                                       value="1" {{ old('recovery_alert', $rule->recovery_alert ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="recovery_alert">
                                    Send recovery notification when alert clears
                                </label>
                            </div>
                        </div>

                        <div class="mt-2">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="disabled" id="disabled"
                                       value="1" {{ old('disabled', $rule->disabled ?? false) ? 'checked' : '' }}>
                                <label class="form-check-label text-muted" for="disabled">
                                    Disable this rule (no alerts will fire)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            {{-- Right column: Notifications --}}
            <div class="col-lg-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-bell me-2"></i>Notifications
                    </div>
                    <div class="card-body">

                        {{-- Email --}}
                        <div class="mb-3">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="notify_email"
                                       id="notify_email" value="1"
                                       {{ old('notify_email', $rule->notify_email ?? true) ? 'checked' : '' }}
                                       onchange="toggleSection('email-section', this.checked)">
                                <label class="form-check-label fw-semibold" for="notify_email">
                                    <i class="bi bi-envelope me-1"></i>Email Notifications
                                </label>
                            </div>
                            <div id="email-section" style="{{ old('notify_email', $rule->notify_email ?? true) ? '' : 'display:none' }}">
                                <label class="form-label small text-muted">Additional Recipients</label>
                                <textarea name="notify_emails" rows="3"
                                          class="form-control form-control-sm @error('notify_emails') is-invalid @enderror"
                                          placeholder="extra@example.com, ops@example.com">{{ old('notify_emails', $rule->notify_emails) }}</textarea>
                                <div class="form-text">Comma-separated. Admin users are always included.</div>
                                @error('notify_emails')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <hr>

                        {{-- Slack --}}
                        <div class="mb-2">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="notify_slack"
                                       id="notify_slack" value="1"
                                       {{ old('notify_slack', $rule->notify_slack ?? false) ? 'checked' : '' }}
                                       onchange="toggleSection('slack-section', this.checked)">
                                <label class="form-check-label fw-semibold" for="notify_slack">
                                    <i class="bi bi-slack me-1"></i>Slack Notifications
                                </label>
                            </div>
                            <div id="slack-section" style="{{ old('notify_slack', $rule->notify_slack ?? false) ? '' : 'display:none' }}">
                                <label class="form-label small text-muted">Webhook URL</label>
                                <input type="url" name="slack_webhook"
                                       value="{{ old('slack_webhook', $rule->slack_webhook) }}"
                                       class="form-control form-control-sm @error('slack_webhook') is-invalid @enderror"
                                       placeholder="https://hooks.slack.com/services/...">
                                @error('slack_webhook')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>
                        {{ $rule->exists ? 'Update Rule' : 'Create Rule' }}
                    </button>
                    <a href="{{ route('admin.alert-rules.index') }}" class="btn btn-outline-secondary">
                        Cancel
                    </a>
                </div>
            </div>

        </div>
    </form>
</div>

<script>
function toggleSection(id, show) {
    const el = document.getElementById(id);
    if (el) el.style.display = show ? '' : 'none';
}
</script>
@endsection
