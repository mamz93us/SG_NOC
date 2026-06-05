@extends('layouts.admin')

@section('content')

@php $editing = $account->exists; @endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">{{ $editing ? 'Edit' : 'New' }} Backup Account</h1>
    <a href="{{ route('admin.backups.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

@if($errors->any())
<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="card"><div class="card-body">
<form method="POST" action="{{ $editing ? route('admin.backups.update', $account) : route('admin.backups.store') }}">
    @csrf
    @if($editing)@method('PUT')@endif

    @if($editing)
    <div class="mb-3">
        <label class="form-label fw-semibold">SFTP/FTP username</label>
        <input class="form-control font-monospace" value="{{ $account->sftpgo_username }}" disabled>
        <div class="form-text">Immutable — it's the folder name and the monitoring key.</div>
    </div>
    @endif

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Link to device <small class="text-muted fw-normal">(optional)</small></label>
            @php $selLink = old('device_link', $editing && $account->device_type ? $account->device_type.':'.$account->device_id : ''); @endphp
            <select name="device_link" class="form-select">
                <option value="">— None (use label) —</option>
                @foreach($linkables as $group => $opts)
                    <optgroup label="{{ $group }}">
                        @foreach($opts as $opt)
                            <option value="{{ $opt['value'] }}" @selected($selLink === $opt['value'])>{{ $opt['label'] }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Label <small class="text-muted fw-normal">(for WHM / non-inventory sources)</small></label>
            <input name="label" class="form-control" value="{{ old('label', $account->label) }}" placeholder="e.g. WHM cPanel server">
        </div>

        <div class="col-md-6">
            <label class="form-label fw-semibold d-block">Protocols</label>
            @php $selP = old('protocols', $account->protocols ?: ['SFTP']); @endphp
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="protocols[]" value="SFTP" id="p_sftp" {{ in_array('SFTP', $selP) ? 'checked' : '' }}>
                <label class="form-check-label" for="p_sftp">SFTP (:2022)</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="protocols[]" value="FTP" id="p_ftp" {{ in_array('FTP', $selP) ? 'checked' : '' }}>
                <label class="form-check-label" for="p_ftp">FTPS (:2121)</label>
            </div>
        </div>

        <div class="col-md-3">
            <label class="form-label fw-semibold">Expected frequency</label>
            <select name="expected_frequency" class="form-select">
                @foreach(['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','manual'=>'Manual (no alert)'] as $v => $l)
                    <option value="{{ $v }}" @selected(old('expected_frequency', $account->expected_frequency) === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Grace (minutes)</label>
            <input type="number" min="0" name="grace_minutes" class="form-control" value="{{ old('grace_minutes', $account->grace_minutes ?? 0) }}">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Quota (MB)</label>
            <input type="number" min="0" name="quota_mb" class="form-control" value="{{ old('quota_mb', $account->quota_mb) }}" placeholder="blank = default">
        </div>

        @if($editing)
        <div class="col-12">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $account->is_active) ? 'checked' : '' }}>
                <label class="form-check-label" for="is_active">Active (uncheck to disable uploads)</label>
            </div>
        </div>
        @endif
    </div>

    <div class="mt-4 d-flex gap-2 align-items-center">
        <button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Create account' }}</button>
        @unless($editing)<span class="text-muted small">A unique username + password are generated and shown once.</span>@endunless
    </div>
</form>
</div></div>

@endsection
