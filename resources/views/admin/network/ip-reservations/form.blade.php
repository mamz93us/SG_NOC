@extends('layouts.admin')
@section('content')

<div class="mb-4 d-flex justify-content-between align-items-center">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-hdd-rack me-2 text-primary"></i>{{ isset($reservation->id) ? 'Edit' : 'Reserve' }} IP Address
        </h4>
        <small class="text-muted">
            <a href="{{ route('admin.network.ip-reservations.index') }}" class="text-decoration-none">IP Reservations</a> / {{ isset($reservation->id) ? 'Edit' : 'New' }}
        </small>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form id="ipForm" method="POST" action="{{ isset($reservation->id) ? route('admin.network.ip-reservations.update', $reservation) : route('admin.network.ip-reservations.store') }}">
            @csrf
            @if(isset($reservation->id)) @method('PUT') @endif

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Branch <span class="text-danger">*</span></label>
                    <select name="branch_id" id="branch_id" class="form-select select2" required>
                        <option value="">Select Branch</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id', $reservation->branch_id ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Subnet (IPAM)</label>
                    <select name="subnet_id" id="subnet_id" class="form-select select2">
                        <option value="">Select Subnet (Optional)</option>
                        @foreach($subnets as $s)
                        <option value="{{ $s->id }}" data-branch="{{ $s->branch_id }}" data-vlan="{{ $s->vlan }}" {{ old('subnet_id', $reservation->subnet_id ?? '') == $s->id ? 'selected' : '' }}>
                            {{ $s->cidr }} ({{ $s->branch?->name }})
                        </option>
                        @endforeach
                    </select>
                    @error('subnet_id') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold d-flex justify-content-between">
                        <span>IP Address <span class="text-danger" id="ip_req_star">*</span></span>
                        @if(!isset($reservation->id))
                        <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" id="btnAutoAssign" style="display:none;">
                            <i class="bi bi-magic"></i> Auto Assign
                        </button>
                        @endif
                    </label>
                    <input type="text" name="ip_address" id="ip_address" class="form-control font-monospace" value="{{ old('ip_address', $reservation->ip_address ?? '') }}" {{ isset($reservation->id) ? 'required' : '' }} placeholder="e.g. 10.2.1.150 (Leave blank if auto-assigning from Subnet)">
                    @error('ip_address') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">VLAN</label>
                    <input type="number" name="vlan" id="vlan" class="form-control" value="{{ old('vlan', $reservation->vlan ?? '') }}" min="0" max="4094">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold d-flex justify-content-between">
                        <span>Associated Asset (Device)</span>
                        <a href="{{ route('admin.devices.create') }}" target="_blank" class="text-decoration-none small"><i class="bi bi-plus"></i> Add Asset</a>
                    </label>
                    <select name="device_id" id="device_id" class="form-select select2">
                        <option value="">No specific asset (or write name below)</option>
                        @foreach($devices as $d)
                        <option value="{{ $d->id }}" data-mac="{{ $d->mac_address }}" data-name="{{ $d->name }}" data-type="{{ $d->type }}" {{ old('device_id', $reservation->device_id ?? '') == $d->id ? 'selected' : '' }}>
                            {{ $d->name }} @if($d->mac_address) ({{ $d->mac_address }}) @endif
                        </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Device Name (Custom)</label>
                    <input type="text" name="device_name" id="device_name" class="form-control" value="{{ old('device_name', $reservation->device_name ?? '') }}" placeholder="e.g. Switch RYD_3">
                </div>
                
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Device Type</label>
                    <select name="device_type" id="device_type" class="form-select">
                        <option value="">Select Type</option>
                        @foreach($deviceTypes as $key => $label)
                        <option value="{{ $key }}" {{ old('device_type', $reservation->device_type ?? '') == $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">MAC Address</label>
                    <input type="text" name="mac_address" id="mac_address" class="form-control font-monospace" value="{{ old('mac_address', $reservation->mac_address ?? '') }}" placeholder="e.g. 6c:c3:b2:84:27:5f">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Assigned To</label>
                    <input type="text" name="assigned_to" class="form-control" value="{{ old('assigned_to', $reservation->assigned_to ?? '') }}" placeholder="Person or system">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes', $reservation->notes ?? '') }}</textarea>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>{{ isset($reservation->id) ? 'Update' : 'Reserve' }}
                </button>
                <a href="{{ route('admin.network.ip-reservations.index') }}" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@push('styles')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
@endpush

@push('scripts')
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap-5'
    });

    const branchSelect = $('#branch_id');
    const subnetSelect = $('#subnet_id');
    const btnAutoAssign = $('#btnAutoAssign');
    const ipInput = $('#ip_address');
    const vlanInput = $('#vlan');
    const deviceSelect = $('#device_id');
    const deviceNameInput = $('#device_name');
    const macInput = $('#mac_address');
    const typeSelect = $('#device_type');
    
    // Filter Subnets based on Branch
    function filterSubnets() {
        const branchId = branchSelect.val();
        let hasSubnets = false;
        subnetSelect.find('option').each(function() {
            if (!$(this).val()) return;
            if (branchId && $(this).data('branch') != branchId) {
                $(this).hide();
            } else {
                $(this).show();
                hasSubnets = true;
            }
        });
        
        // Reset subnet selection if current selected is hidden
        if (subnetSelect.find('option:selected').css('display') === 'none') {
            subnetSelect.val('').trigger('change.select2');
        }
    }
    
    branchSelect.on('change', filterSubnets);
    filterSubnets();
    
    // Auto populate fields based on Device selection
    deviceSelect.on('change', function() {
        if (!$(this).val()) return;
        const selected = $(this).find(':selected');
        const mac = selected.data('mac');
        const name = selected.data('name');
        const type = selected.data('type');
        
        if (mac && !macInput.val()) macInput.val(mac);
        if (name && !deviceNameInput.val()) deviceNameInput.val(name);
        if (type && !typeSelect.val()) typeSelect.val(type);
    });

    // Auto Assign IP feature
    subnetSelect.on('change', function() {
        if ($(this).val() && !@json(isset($reservation->id))) {
            btnAutoAssign.show();
            $('#ip_req_star').hide();
            ipInput.prop('required', false);
            
            // Auto fill VLAN if available
            const vlan = $(this).find(':selected').data('vlan');
            if (vlan && !vlanInput.val()) {
                vlanInput.val(vlan);
            }
        } else {
            btnAutoAssign.hide();
            $('#ip_req_star').show();
            ipInput.prop('required', true);
        }
    });

    btnAutoAssign.on('click', function() {
        const subnetId = subnetSelect.val();
        if (!subnetId) return;
        
        const btn = $(this);
        const originalHtml = btn.html();
        btn.html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>').prop('disabled', true);
        
        $.ajax({
            url: '{{ route('admin.network.ip-reservations.get-available-ip') }}',
            data: { subnet_id: subnetId },
            success: function(res) {
                if (res.ip_address) {
                    ipInput.val(res.ip_address);
                    if (res.vlan) vlanInput.val(res.vlan);
                    
                    // Add success animation
                    ipInput.removeClass('is-invalid').addClass('is-valid');
                    setTimeout(() => ipInput.removeClass('is-valid'), 2000);
                } else {
                    alert('No IP available in the selected subnet.');
                }
            },
            error: function(err) {
                alert(err.responseJSON?.message || 'Error occurred while fetching IP.');
            },
            complete: function() {
                btn.html(originalHtml).prop('disabled', false);
            }
        });
    });
    
    // Trigger initialization
    if (subnetSelect.val()) subnetSelect.trigger('change');
});
</script>
@endpush
@endsection
