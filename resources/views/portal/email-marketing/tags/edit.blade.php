@extends('layouts.portal')

@section('title', $tag->exists ? 'Edit tag' : 'New tag')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form class="card shadow-sm"
          method="POST"
          action="{{ $tag->exists ? route('portal.marketing.tags.update', $tag) : route('portal.marketing.tags.store') }}">
        @csrf
        @if ($tag->exists) @method('PUT') @endif
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" required value="{{ old('name', $tag->name) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Color (hex)</label>
                    <input type="color" name="color" class="form-control form-control-color" value="{{ old('color', $tag->color ?: '#6c757d') }}">
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end">
            <a href="{{ route('portal.marketing.tags.index') }}" class="btn btn-link">Cancel</a>
            <button class="btn btn-primary">Save tag</button>
        </div>
    </form>
</div>
@endsection
