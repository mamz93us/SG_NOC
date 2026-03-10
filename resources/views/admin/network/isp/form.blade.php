@extends('layouts.admin')
@section('content')

<div class="mb-4">
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-globe2 me-2 text-primary"></i>{{ isset($isp) ? 'Edit' : 'Add' }} ISP Connection
    </h4>
    <small class="text-muted">
        <a href="{{ route('admin.network.isp.index') }}" class="text-decoration-none">ISP Connections</a> / {{ isset($isp) ? 'Edit' : 'New' }}
    </small>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ isset($isp) ? route('admin.network.isp.update', $isp) : route('admin.network.isp.store') }}">
            @csrf
            @if(isset($isp)) @method('PUT') @endif

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Branch <span class="text-danger">*</span></label>
                    <select name="branch_id" class="form-select" required>
                        <option value="">Select Branch</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id', $isp->branch_id ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Provider <span class="text-danger">*</span></label>
                    <input type="text" name="provider" class="form-control" value="{{ old('provider', $isp->provider ?? '') }}" required placeholder="e.g. STC, Mobily, Zain">
                    @error('provider') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Circuit ID</label>
                    <input type="text" name="circuit_id" class="form-control" value="{{ old('circuit_id', $isp->circuit_id ?? '') }}" placeholder="ISP circuit reference">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Download Speed (Mbps)</label>
                    <input type="number" name="speed_down" class="form-control" value="{{ old('speed_down', $isp->speed_down ?? '') }}" min="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Upload Speed (Mbps)</label>
                    <input type="number" name="speed_up" class="form-control" value="{{ old('speed_up', $isp->speed_up ?? '') }}" min="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Static IP</label>
                    <input type="text" name="static_ip" class="form-control font-monospace" value="{{ old('static_ip', $isp->static_ip ?? '') }}" placeholder="e.g. 203.0.113.10">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Gateway</label>
                    <input type="text" name="gateway" class="form-control font-monospace" value="{{ old('gateway', $isp->gateway ?? '') }}" placeholder="e.g. 203.0.113.1">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Subnet</label>
                    <input type="text" name="subnet" class="form-control font-monospace" value="{{ old('subnet', $isp->subnet ?? '') }}" placeholder="e.g. /29 or 255.255.255.248">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Router Device</label>
                    <select name="router_device_id" class="form-select">
                        <option value="">None</option>
                        @foreach($routers as $r)
                        <option value="{{ $r->id }}" {{ old('router_device_id', $isp->router_device_id ?? '') == $r->id ? 'selected' : '' }}>{{ $r->name }} ({{ ucfirst($r->type) }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Contract Start</label>
                    <input type="date" name="contract_start" class="form-control" value="{{ old('contract_start', isset($isp) && $isp->contract_start ? $isp->contract_start->format('Y-m-d') : '') }}">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Contract End</label>
                    <input type="date" name="contract_end" class="form-control" value="{{ old('contract_end', isset($isp) && $isp->contract_end ? $isp->contract_end->format('Y-m-d') : '') }}">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Monthly Cost</label>
                    <input type="number" name="monthly_cost" class="form-control" value="{{ old('monthly_cost', $isp->monthly_cost ?? '') }}" min="0" step="0.01">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes', $isp->notes ?? '') }}</textarea>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>{{ isset($isp) ? 'Update' : 'Create' }}
                </button>
                <a href="{{ route('admin.network.isp.index') }}" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
