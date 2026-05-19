@extends('layouts.portal')

@section('title', $course->exists ? 'Edit course' : 'New course')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form class="card shadow-sm"
          method="POST"
          action="{{ $course->exists ? route('portal.marketing.courses.update', $course) : route('portal.marketing.courses.store') }}">
        @csrf
        @if ($course->exists) @method('PUT') @endif

        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" required
                           value="{{ old('name', $course->name) }}">
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2">{{ old('description', $course->description) }}</textarea>
                </div>

                <div class="col-12"><hr class="mt-3 mb-0"><h6 class="text-muted mt-3">Send defaults</h6>
                    <small class="text-muted">Used to pre-fill the &laquo;Send certificates&raquo; form. Can be overridden per send.</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Default email template</label>
                    <select name="default_template_id" class="form-select">
                        <option value="">(none — pick on each send)</option>
                        @foreach ($templates as $t)
                            <option value="{{ $t->id }}" @selected(old('default_template_id', $course->default_template_id) == $t->id)>
                                {{ $t->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Default subject</label>
                    <input type="text" name="default_subject" class="form-control"
                           placeholder="e.g. Your {{ '{{course_name}}' }} certificate"
                           value="{{ old('default_subject', $course->default_subject) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Default From email</label>
                    <input type="email" name="default_from_email" class="form-control"
                           value="{{ old('default_from_email', $course->default_from_email) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Default From name</label>
                    <input type="text" name="default_from_name" class="form-control"
                           value="{{ old('default_from_name', $course->default_from_name) }}">
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end">
            <a href="{{ route('portal.marketing.courses.index') }}" class="btn btn-link">Cancel</a>
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save course</button>
        </div>
    </form>
</div>
@endsection
