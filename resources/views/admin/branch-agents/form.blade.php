@extends('layouts.admin')

@section('title', $agent->exists ? 'Edit Branch Agent' : 'Add Branch Agent')

@section('content')
<div class="container-fluid py-3">
    <h4 class="mb-3">{{ $agent->exists ? "Edit branch agent '{$agent->code}'" : 'Add branch agent' }}</h4>

    <div class="card">
        <div class="card-body">
            <form action="{{ $agent->exists ? route('admin.branch-agents.update', $agent) : route('admin.branch-agents.store') }}"
                  method="POST">
                @csrf
                @if($agent->exists) @method('PUT') @endif

                @if($errors->any())
                    <div class="alert alert-danger py-2">
                        <ul class="mb-0 small">
                            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Branch code <span class="text-danger">*</span></label>
                        <input type="text" name="code" value="{{ old('code', $agent->code) }}"
                               class="form-control" placeholder="jed, ryd…" maxlength="8" required>
                        <small class="text-muted">2–8 lowercase letters/digits. Also keys the log-collector row.</small>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Display name <span class="text-danger">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $agent->name) }}"
                               class="form-control" placeholder="Jeddah office" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <div class="form-check form-switch mt-2">
                            <input type="hidden" name="enabled" value="0">
                            <input class="form-check-input" type="checkbox" id="enabledSwitch"
                                   name="enabled" value="1" @if(old('enabled', $agent->enabled)) checked @endif>
                            <label class="form-check-label" for="enabledSwitch">Enabled</label>
                        </div>
                    </div>

                    <div class="col-md-9">
                        <label class="form-label">Hostname</label>
                        <input type="text" name="hostname" value="{{ old('hostname', $agent->hostname) }}"
                               class="form-control font-monospace" placeholder="10.1.0.5 (set automatically on enrollment)">
                        <small class="text-muted">IPsec tunnel-side IP/host. The agent reports this on enrollment; you can leave it blank.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Port <span class="text-danger">*</span></label>
                        <input type="number" name="port" value="{{ old('port', $agent->port ?: 8080) }}"
                               class="form-control" min="1" max="65535" required>
                        <small class="text-muted">Agent UI + API port.</small>
                    </div>

                    <div class="col-12"><hr class="my-1"><strong class="small text-muted">DDNS (optional)</strong></div>

                    <div class="col-md-4">
                        <label class="form-label">DNS subdomain</label>
                        <input type="text" name="dns_subdomain" value="{{ old('dns_subdomain', $agent->dns_subdomain) }}"
                               class="form-control font-monospace" placeholder="jed">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">DNS domain</label>
                        <input type="text" name="dns_domain" value="{{ old('dns_domain', $agent->dns_domain) }}"
                               class="form-control font-monospace" placeholder="{{ config('branch_agents.dns_domain') }}">
                        <small class="text-muted">Record: <code>subdomain.domain</code>.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">GoDaddy account</label>
                        <select name="dns_account_id" class="form-select">
                            <option value="">— none —</option>
                            @foreach($dnsAccounts as $acc)
                                <option value="{{ $acc->id }}" @selected(old('dns_account_id', $agent->dns_account_id) == $acc->id)>
                                    {{ $acc->label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">VPN tunnel</label>
                        <select name="vpn_tunnel_id" class="form-select">
                            <option value="">— none —</option>
                            @foreach($tunnels as $t)
                                <option value="{{ $t->id }}" @selected(old('vpn_tunnel_id', $agent->vpn_tunnel_id) == $t->id)>
                                    {{ $t->name }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">
                            On DDNS update, this tunnel's remote endpoint follows the WAN IP
                            (safest when its <code>remote_public_ip</code> is set to the FQDN).
                        </small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" rows="2" class="form-control">{{ old('notes', $agent->notes) }}</textarea>
                    </div>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>{{ $agent->exists ? 'Save changes' : 'Create & issue enrollment code' }}
                    </button>
                    <a href="{{ route('admin.branch-agents.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
