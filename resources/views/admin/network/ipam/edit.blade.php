@extends('layouts.admin')
@section('title', 'Edit Subnet: ' . $subnet->cidr)

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Edit Subnet</h5>
                    <a href="{{ route('admin.network.ipam.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.network.ipam.update', $subnet) }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label class="form-label">Branch <span class="text-danger">*</span></label>
                            <select name="branch_id" class="form-select @error('branch_id') is-invalid @enderror" required>
                                <option value="">Select Branch</option>
                                @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ old('branch_id', $subnet->branch_id) == $b->id ? 'selected' : '' }}>
                                    {{ $b->name }}
                                </option>
                                @endforeach
                            </select>
                            @error('branch_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">CIDR <span class="text-danger">*</span></label>
                            <input type="text" name="cidr" class="form-control @error('cidr') is-invalid @enderror" 
                                   value="{{ old('cidr', $subnet->cidr) }}" placeholder="192.168.1.0/24" required>
                            @error('cidr')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">VLAN</label>
                                <input type="number" name="vlan" class="form-control @error('vlan') is-invalid @enderror" 
                                       value="{{ old('vlan', $subnet->vlan) }}" min="1" max="4094">
                                @error('vlan')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Gateway</label>
                                <input type="text" name="gateway" class="form-control @error('gateway') is-invalid @enderror" 
                                       value="{{ old('gateway', $subnet->gateway) }}" placeholder="192.168.1.1">
                                @error('gateway')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-control @error('description') is-invalid @enderror" 
                                   value="{{ old('description', $subnet->description) }}">
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> Update Subnet
                            </button>
                            
                            <button type="button" class="btn btn-outline-danger" onclick="confirmDelete()">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>
                    </form>

                    <form id="deleteForm" method="POST" action="{{ route('admin.network.ipam.destroy', $subnet) }}" style="display:none">
                        @csrf
                        @method('DELETE')
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function confirmDelete() {
    if (confirm('Are you sure you want to delete this subnet? This will NOT delete associated reservations or leases, but the IP grid will be lost.')) {
        document.getElementById('deleteForm').submit();
    }
}
</script>
@endpush
@endsection
