@extends('layouts.admin')
@section('title', 'Add RADIUS NAS Client')

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-router me-2 text-primary"></i>Add NAS Client</h4>
            <small class="text-muted">A switch or AP allowed to authenticate MACs against this RADIUS server.</small>
        </div>
        <a href="{{ route('admin.radius.nas.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    <form action="{{ route('admin.radius.nas.store') }}" method="POST">
        @csrf
        @include('admin.radius.nas._form', ['nas' => null])

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-lg me-1"></i>Save NAS Client
            </button>
            <a href="{{ route('admin.radius.nas.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

</div>
@endsection
