@csrf

@if($errors->any())
    <div class="alert alert-danger py-2">
        <ul class="mb-0 small">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-md-3">
        <label class="form-label">Branch <span class="text-danger">*</span></label>
        <select name="branch_log_collector_id" class="form-select" required>
            <option value="">— pick a branch —</option>
            @foreach($branches as $b)
                <option value="{{ $b->id }}"
                    @if(old('branch_log_collector_id', $device->branch_log_collector_id) == $b->id) selected @endif>
                    {{ $b->name }} ({{ $b->code }})
                </option>
            @endforeach
        </select>
    </div>

    <div class="col-md-5">
        <label class="form-label">Display name <span class="text-danger">*</span></label>
        <input type="text" name="name"
               value="{{ old('name', $device->name) }}"
               class="form-control" placeholder="JED Sophos firewall" required>
    </div>

    <div class="col-md-4">
        <label class="form-label">Status</label>
        <div class="form-check form-switch mt-2">
            <input type="hidden" name="enabled" value="0">
            <input class="form-check-input" type="checkbox" id="enabledSwitch"
                   name="enabled" value="1"
                   @if(old('enabled', $device->enabled)) checked @endif>
            <label class="form-check-label" for="enabledSwitch">Enabled (Telegraf polls this)</label>
        </div>
    </div>

    <div class="col-md-6">
        <label class="form-label">Host (IP / DNS) <span class="text-danger">*</span></label>
        <input type="text" name="host"
               value="{{ old('host', $device->host) }}"
               class="form-control font-monospace"
               placeholder="10.3.0.1" required>
        <small class="text-muted">Must be reachable from the branch VM.</small>
    </div>

    <div class="col-md-3">
        <label class="form-label">Port</label>
        <input type="number" name="snmp_port"
               value="{{ old('snmp_port', $device->snmp_port ?: 161) }}"
               class="form-control" min="1" max="65535" required>
    </div>

    <div class="col-md-3">
        <label class="form-label">Polling interval</label>
        <div class="input-group">
            <input type="number" name="polling_interval_s"
                   value="{{ old('polling_interval_s', $device->polling_interval_s ?: 60) }}"
                   class="form-control" min="10" max="3600" required>
            <span class="input-group-text">sec</span>
        </div>
    </div>

    <div class="col-md-4">
        <label class="form-label">Device type <span class="text-danger">*</span></label>
        <select name="device_type" class="form-select" required>
            @foreach($types as $key => $label)
                <option value="{{ $key }}" @if(old('device_type', $device->device_type)===$key) selected @endif>
                    {{ $label }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">Determines which OID set Telegraf polls.</small>
    </div>

    <div class="col-md-4">
        <label class="form-label">SNMP version</label>
        <select name="snmp_version" class="form-select" required>
            <option value="2c" @if(old('snmp_version', $device->snmp_version)==='2c') selected @endif>v2c (recommended)</option>
            <option value="1"  @if(old('snmp_version', $device->snmp_version)==='1') selected @endif>v1</option>
            <option value="3"  @if(old('snmp_version', $device->snmp_version)==='3') selected @endif>v3 (not yet supported)</option>
        </select>
    </div>

    <div class="col-md-4">
        <label class="form-label">
            Community
            @if($device->exists)
                <small class="text-muted">(leave blank to keep)</small>
            @else
                <span class="text-danger">*</span>
            @endif
        </label>
        <input type="text" name="snmp_community"
               class="form-control font-monospace"
               placeholder="public"
               autocomplete="off"
               {{ $device->exists ? '' : 'required' }}>
        <small class="text-muted">Encrypted at rest. Default for most read-only setups: <code>public</code>.</small>
    </div>

    <div class="col-md-12">
        <label class="form-label">Notes</label>
        <textarea name="notes" rows="2" class="form-control"
                  placeholder="Optional — purpose, location, owner, anything else">{{ old('notes', $device->notes) }}</textarea>
    </div>
</div>

<div class="mt-3 d-flex gap-2">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i>{{ $device->exists ? 'Save changes' : 'Add device' }}
    </button>
    <a href="{{ route('admin.snmp-devices.index') }}" class="btn btn-outline-secondary">Cancel</a>
</div>
