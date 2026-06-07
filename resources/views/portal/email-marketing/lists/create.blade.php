@extends('layouts.marketing')

@section('title', $list->exists ? 'Edit list' : 'New list')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form class="card shadow-sm"
          method="POST"
          action="{{ $list->exists ? route('portal.marketing.lists.update', $list) : route('portal.marketing.lists.store') }}">
        @csrf
        @if ($list->exists) @method('PUT') @endif

        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" required
                           value="{{ old('name', $list->name) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control"
                           value="{{ old('description', $list->description) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Default From email</label>
                    <input type="email" name="default_from_email" class="form-control"
                           value="{{ old('default_from_email', $list->default_from_email) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Default From name</label>
                    <input type="text" name="default_from_name" class="form-control"
                           value="{{ old('default_from_name', $list->default_from_name) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Default Reply-to</label>
                    <input type="email" name="default_reply_to" class="form-control"
                           value="{{ old('default_reply_to', $list->default_reply_to) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Auto-sync from employees with domain</label>
                    <input type="text" name="auto_domain" class="form-control"
                           placeholder="e.g. samirgroup.com"
                           value="{{ old('auto_domain', $list->auto_domain) }}">
                    <small class="text-muted">
                        Leave blank for a manual list. When set, membership mirrors
                        active employees whose email ends with @&lt;domain&gt; — manual
                        subscriber edits will be reverted on the next sync.
                    </small>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="double_opt_in"
                               name="double_opt_in" value="1"
                               @checked(old('double_opt_in', $list->double_opt_in ?? false))>
                        <label class="form-check-label" for="double_opt_in">
                            Require double opt-in (recommended)
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end">
            <a href="{{ route('portal.marketing.lists.index') }}" class="btn btn-link">Cancel</a>
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save list</button>
        </div>
    </form>
</div>
@endsection
