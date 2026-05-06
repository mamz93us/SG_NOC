@extends('layouts.admin')
@section('title', 'Edit RADIUS NAS Client')

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-router me-2 text-primary"></i>Edit NAS Client</h4>
            <small class="text-muted">{{ $nas->shortname }} ({{ $nas->nasname }})</small>
        </div>
        <a href="{{ route('admin.radius.nas.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    <form action="{{ route('admin.radius.nas.update', $nas) }}" method="POST">
        @csrf
        @method('PUT')
        @include('admin.radius.nas._form', ['nas' => $nas])

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-lg me-1"></i>Save Changes
            </button>
            <a href="{{ route('admin.radius.nas.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

</div>
@endsection
