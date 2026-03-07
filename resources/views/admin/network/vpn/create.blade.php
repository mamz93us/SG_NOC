@extends('layouts.admin')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.network.vpn.index') }}" class="btn btn-link link-secondary ps-0">
        <i class="bi bi-arrow-left me-1"></i> Back to VPN Hub
    </a>
    <h2 class="h3 mt-2">Add VPN Tunnel</h2>
    <p class="text-muted small">Configure a new IPsec site-to-site tunnel using strongSwan.</p>
</div>

<form action="{{ route('admin.network.vpn.store') }}" method="POST">
    @csrf
    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title mb-0">Basic Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Branch</label>
                            <select name="branch_id" class="form-select @error('branch_id') is-invalid @enderror" required>
                                <option value="">Select Branch...</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }}>
                                        {{ $branch->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tunnel Name</label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" 
                                   value="{{ old('name') }}" placeholder="e.g. BranchA_Tunnel" required>
                            <div class="form-text small">Alphanumeric and underscores only.</div>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Local Identity (Optional)</label>
                            <input type="text" name="local_id" class="form-control @error('local_id') is-invalid @enderror" 
                                   value="{{ old('local_id') }}" placeholder="e.g. vpn.example.com">
                            <div class="form-text small">Use if your side needs a specific FQDN/ID.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Remote Identity (Optional)</label>
                            <input type="text" name="remote_id" class="form-control @error('remote_id') is-invalid @enderror" 
                                   value="{{ old('remote_id') }}" placeholder="e.g. remote.example.com">
                            <div class="form-text small">Matches Sophos/Firewall "Local ID".</div>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-bold">Remote Public IP</label>
                            <input type="text" name="remote_public_ip" class="form-control @error('remote_public_ip') is-invalid @enderror" 
                                   value="{{ old('remote_public_ip') }}" placeholder="X.X.X.X" required>
                            @error('remote_public_ip') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Remote Subnet(s)</label>
                            <input type="text" name="remote_subnet" class="form-control @error('remote_subnet') is-invalid @enderror" 
                                   value="{{ old('remote_subnet') }}" placeholder="e.g. 192.168.2.0/24" required>
                            <div class="form-text small">Multiple subnets: <code>10.1.0.0/24, 10.2.0.0/24</code></div>
                            @error('remote_subnet') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Local Subnet(s)</label>
                            <input type="text" name="local_subnet" class="form-control @error('local_subnet') is-invalid @enderror" 
                                   value="{{ old('local_subnet', $defaultLocalSubnet) }}" placeholder="e.g. 10.0.0.0/16" required>
                            <div class="form-text small">Multiple subnets: <code>10.0.0.0/16, 172.16.0.0/12</code></div>
                            @error('local_subnet') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-bold">Pre-Shared Key (PSK)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password" name="pre_shared_key" class="form-control @error('pre_shared_key') is-invalid @enderror" 
                                       placeholder="Enter secret key" required>
                            </div>
                            @error('pre_shared_key') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3" data-bs-toggle="collapse" data-bs-target="#advancedSettings" style="cursor: pointer;">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Advanced Settings (IPsec)</h5>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                </div>
                <div id="advancedSettings" class="collapse">
                    <div class="card-body border-top">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">IKE Version</label>
                                <select name="ike_version" class="form-select">
                                    <option value="IKEv2" {{ old('ike_version') == 'IKEv2' ? 'selected' : '' }}>IKEv2</option>
                                    <option value="IKEv1" {{ old('ike_version') == 'IKEv1' ? 'selected' : '' }}>IKEv1</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Encryption</label>
                                <select name="encryption" class="form-select">
                                    <option value="aes256" {{ old('encryption', 'aes256') == 'aes256' ? 'selected' : '' }}>AES-256</option>
                                    <option value="aes128" {{ old('encryption') == 'aes128' ? 'selected' : '' }}>AES-128</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Hash</label>
                                <select name="hash" class="form-select">
                                    <option value="sha256" {{ old('hash', 'sha256') == 'sha256' ? 'selected' : '' }}>SHA-256</option>
                                    <option value="sha512" {{ old('hash') == 'sha512' ? 'selected' : '' }}>SHA-512</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">DH Group</label>
                                <select name="dh_group" class="form-select">
                                    <option value="14" {{ old('dh_group', 14) == 14 ? 'selected' : '' }}>Group 14 (2048-bit)</option>
                                    <option value="15" {{ old('dh_group') == 15 ? 'selected' : '' }}>Group 15 (3072-bit)</option>
                                    <option value="16" {{ old('dh_group') == 16 ? 'selected' : '' }}>Group 16 (4096-bit)</option>
                                    <option value="19" {{ old('dh_group') == 19 ? 'selected' : '' }}>Group 19 (ECP 256)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">DPD Delay (sec)</label>
                                <input type="number" name="dpd_delay" class="form-control" value="{{ old('dpd_delay', 30) }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Lifetime</label>
                                <input type="text" name="lifetime" class="form-control" value="{{ old('lifetime', '8h') }}">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4 sticky-top" style="top: 20px;">
                <div class="card-body">
                    <h5 class="card-title mb-3">Actions</h5>
                    <button type="submit" class="btn btn-primary w-100 mb-2">
                        Create & Initiate Tunnel
                    </button>
                    <a href="{{ route('admin.network.vpn.index') }}" class="btn btn-outline-secondary w-100">
                        Cancel
                    </a>
                    
                    <hr>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Saving this tunnel will automatically:
                        <ul class="ps-3 mb-0 mt-1">
                            <li>Generate <code>swanctl.conf</code></li>
                            <li>Reload strongSwan</li>
                            <li>Attempt to initiate Child SA</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
