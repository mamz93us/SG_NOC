@extends('layouts.admin')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.network.vpn.index') }}" class="btn btn-link link-secondary ps-0">
        <i class="bi bi-arrow-left me-1"></i> Back to VPN Hub
    </a>
    <h2 class="h3 mt-2">Edit VPN Tunnel: {{ $tunnel->name }}</h2>
    <p class="text-muted small">Update configuration for this IPsec tunnel.</p>
</div>

<form action="{{ route('admin.network.vpn.update', $tunnel) }}" method="POST">
    @csrf
    @method('PUT')
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
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ old('branch_id', $tunnel->branch_id) == $branch->id ? 'selected' : '' }}>
                                        {{ $branch->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tunnel Name</label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" 
                                   value="{{ old('name', $tunnel->name) }}" placeholder="e.g. BranchA_Tunnel" required>
                            <div class="form-text small">Alphanumeric and underscores only.</div>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Local Identity (Optional)</label>
                            <input type="text" name="local_id" class="form-control @error('local_id') is-invalid @enderror" 
                                   value="{{ old('local_id', $tunnel->local_id) }}" placeholder="e.g. vpn.example.com">
                            <div class="form-text small">Use if your side needs a specific FQDN/ID.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Remote Identity (Optional)</label>
                            <input type="text" name="remote_id" class="form-control @error('remote_id') is-invalid @enderror" 
                                   value="{{ old('remote_id', $tunnel->remote_id) }}" placeholder="e.g. remote.example.com">
                            <div class="form-text small">Matches Sophos/Firewall "Local ID".</div>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-bold">Remote Gateway (IP or DNS)</label>
                            <input type="text" name="remote_public_ip" class="form-control @error('remote_public_ip') is-invalid @enderror" 
                                   value="{{ old('remote_public_ip', $tunnel->remote_public_ip) }}" placeholder="e.g. 212.x.x.x or branch.example.com" required>
                            @error('remote_public_ip') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Remote Subnet(s)</label>
                            <input type="text" name="remote_subnet" class="form-control @error('remote_subnet') is-invalid @enderror" 
                                   value="{{ old('remote_subnet', $tunnel->remote_subnet) }}" placeholder="e.g. 192.168.2.0/24" required>
                            <div class="form-text small">Multiple subnets: <code>10.1.0.0/24, 10.2.0.0/24</code></div>
                            @error('remote_subnet') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Local Subnet(s)</label>
                            <input type="text" name="local_subnet" class="form-control @error('local_subnet') is-invalid @enderror" 
                                   value="{{ old('local_subnet', $tunnel->local_subnet) }}" placeholder="e.g. 192.168.1.0/24" required>
                            <div class="form-text small">Multiple subnets: <code>10.0.0.0/16, 172.16.0.0/12</code></div>
                            @error('local_subnet') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-bold">Pre-Shared Key (PSK)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password" name="pre_shared_key" class="form-control @error('pre_shared_key') is-invalid @enderror" 
                                       placeholder="Leave blank to keep current key">
                            </div>
                            <div class="form-text small text-info"><i class="bi bi-info-circle me-1"></i> Current key is encrypted and hidden for security.</div>
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
                <div id="advancedSettings" class="collapse show">
                    <div class="card-body border-top">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">IKE Version</label>
                                <select name="ike_version" class="form-select">
                                    <option value="IKEv2" {{ old('ike_version', $tunnel->ike_version) == 'IKEv2' ? 'selected' : '' }}>IKEv2</option>
                                    <option value="IKEv1" {{ old('ike_version', $tunnel->ike_version) == 'IKEv1' ? 'selected' : '' }}>IKEv1</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Encryption</label>
                                <select name="encryption" class="form-select">
                                    <option value="aes256" {{ old('encryption', $tunnel->encryption) == 'aes256' ? 'selected' : '' }}>AES-256</option>
                                    <option value="aes128" {{ old('encryption', $tunnel->encryption) == 'aes128' ? 'selected' : '' }}>AES-128</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Hash</label>
                                <select name="hash" class="form-select">
                                    <option value="sha256" {{ old('hash', $tunnel->hash) == 'sha256' ? 'selected' : '' }}>SHA-256</option>
                                    <option value="sha512" {{ old('hash', $tunnel->hash) == 'sha512' ? 'selected' : '' }}>SHA-512</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">DH Group</label>
                                <select name="dh_group" class="form-select">
                                    <option value="14" {{ old('dh_group', $tunnel->dh_group) == 14 ? 'selected' : '' }}>Group 14 (2048-bit)</option>
                                    <option value="15" {{ old('dh_group', $tunnel->dh_group) == 15 ? 'selected' : '' }}>Group 15 (3072-bit)</option>
                                    <option value="16" {{ old('dh_group', $tunnel->dh_group) == 16 ? 'selected' : '' }}>Group 16 (4096-bit)</option>
                                    <option value="19" {{ old('dh_group', $tunnel->dh_group) == 19 ? 'selected' : '' }}>Group 19 (ECP 256)</option>
                                    <option value="31" {{ old('dh_group', $tunnel->dh_group) == 31 ? 'selected' : '' }}>Group 31 (Curve25519)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">DPD Delay (sec)</label>
                                <input type="number" name="dpd_delay" class="form-control" value="{{ old('dpd_delay', $tunnel->dpd_delay) }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Lifetime</label>
                                <input type="text" name="lifetime" class="form-control" value="{{ old('lifetime', $tunnel->lifetime) }}">
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
                        Update Configuration
                    </button>
                    <a href="{{ route('admin.network.vpn.index') }}" class="btn btn-outline-secondary w-100">
                        Cancel
                    </a>
                    
                    <hr>
                    <div class="alert alert-warning py-2 small mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Updating will:
                        <ul class="ps-3 mb-0 mt-1">
                            <li>Re-generate <code>swanctl.conf</code></li>
                            <li>Reload strongSwan configurations</li>
                            <li>Old tunnel files will be removed if name changed</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="card shadow-sm border-0 mt-3">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0">Tunnel Info</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Status:</span>
                            <span class="fw-bold text-{{ $tunnel->status == 'up' ? 'success' : 'danger' }}">{{ strtoupper($tunnel->status) }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Last Checked:</span>
                            <span>{{ $tunnel->last_checked_at ? $tunnel->last_checked_at->format('M d, H:i') : 'N/A' }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Created:</span>
                            <span>{{ $tunnel->created_at->format('M d, Y') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
