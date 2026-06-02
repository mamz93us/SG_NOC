@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-plus-circle me-2 text-primary"></i>Add Phone to GDMS</h4>
    <a href="{{ route('admin.phones.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2">{{ session('error') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button></div>
@endif
@if($errors->any())
<div class="alert alert-danger py-2"><ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="row">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.phones.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">MAC Address <span class="text-danger">*</span></label>
                        <input type="text" name="mac" value="{{ old('mac') }}" class="form-control font-monospace" placeholder="C0:74:AD:00:11:22" required>
                        <div class="form-text">12 hex digits. Colons/dashes optional.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Serial Number <span class="text-danger">*</span></label>
                        <input type="text" name="sn" value="{{ old('sn') }}" class="form-control" required>
                        <div class="form-text">GDMS requires both MAC and serial to prove ownership of the device.</div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Display Name</label>
                            <input type="text" name="name" value="{{ old('name') }}" class="form-control" placeholder="Reception GRP2601">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Model</label>
                            <input type="text" name="model" value="{{ old('model') }}" class="form-control" placeholder="GRP2601">
                        </div>
                    </div>
                    @if(! empty($sites))
                    <div class="mb-3">
                        <label class="form-label">GDMS Site</label>
                        <select name="site_id" class="form-select">
                            <option value="">— default —</option>
                            @foreach($sites as $s)
                                <option value="{{ $s['id'] ?? ($s['siteId'] ?? '') }}">{{ $s['name'] ?? ($s['siteName'] ?? ($s['id'] ?? '')) }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif
                    <div class="mb-3">
                        <label class="form-label">Assign to Employee (optional)</label>
                        <select name="employee_id" class="form-select">
                            <option value="">— none —</option>
                            @foreach($employees as $e)
                                <option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $e->name }}@if($e->extension_number) (ext {{ $e->extension_number }})@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-cloud-upload me-1"></i>Add to GDMS</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="alert alert-info small">
            <strong><i class="bi bi-info-circle me-1"></i>What happens</strong>
            <ol class="mb-0 ps-3 mt-2">
                <li>The device is claimed into your GDMS org (by MAC + serial).</li>
                <li>An ITAM asset record is created (type: phone, source: gdms).</li>
                <li>If you pick an employee, the phone is assigned to them.</li>
                <li>Assign a SIP account from the phone's detail page once it is online.</li>
            </ol>
        </div>
    </div>
</div>

@endsection
