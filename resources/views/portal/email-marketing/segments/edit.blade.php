@extends('layouts.portal')

@section('title', $segment->exists ? 'Edit segment' : 'New segment')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form class="card shadow-sm"
          method="POST"
          action="{{ $segment->exists ? route('portal.marketing.segments.update', $segment) : route('portal.marketing.segments.store') }}">
        @csrf
        @if ($segment->exists) @method('PUT') @endif
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" required value="{{ old('name', $segment->name) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" value="{{ old('description', $segment->description) }}">
                </div>
                <div class="col-12">
                    <label class="form-label">Rules (JSON)</label>
                    <textarea name="rules" class="form-control font-monospace" rows="10">{{ old('rules', json_encode($segment->rules, JSON_PRETTY_PRINT)) }}</textarea>
                    <small class="text-muted">
                        Shape: <code>{"operator":"AND|OR","conditions":[{"field":"status","op":"=","value":"subscribed"},{"field":"tags","op":"includes","value":[1,2]},{"field":"attributes.country","op":"=","value":"SA"}]}</code>
                    </small>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end">
            <a href="{{ route('portal.marketing.segments.index') }}" class="btn btn-link">Cancel</a>
            <button class="btn btn-primary">Save segment</button>
        </div>
    </form>
</div>
@endsection
