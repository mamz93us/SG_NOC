@extends('layouts.portal')

@section('title', $subscriber->exists ? 'Edit subscriber' : 'New subscriber')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form class="card shadow-sm"
          method="POST"
          action="{{ $subscriber->exists ? route('portal.marketing.subscribers.update', $subscriber) : route('portal.marketing.subscribers.store') }}">
        @csrf
        @if ($subscriber->exists) @method('PUT') @endif

        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required
                           value="{{ old('email', $subscriber->email) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">First name</label>
                    <input type="text" name="first_name" class="form-control"
                           value="{{ old('first_name', $subscriber->first_name) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Last name</label>
                    <input type="text" name="last_name" class="form-control"
                           value="{{ old('last_name', $subscriber->last_name) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        @foreach (['pending','subscribed','unsubscribed','bounced','complained'] as $st)
                            <option value="{{ $st }}" @selected(old('status', $subscriber->status ?: 'subscribed') === $st)>{{ $st }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-9">
                    <label class="form-label">Lists</label>
                    <select name="list_ids[]" class="form-select" multiple size="4">
                        @foreach ($lists as $l)
                            <option value="{{ $l->id }}"
                                @selected(in_array($l->id, old('list_ids', $subscriber->lists?->pluck('id')->all() ?? [])))>{{ $l->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Tags</label>
                    <select name="tag_ids[]" class="form-select" multiple size="3">
                        @foreach ($tags as $t)
                            <option value="{{ $t->id }}"
                                @selected(in_array($t->id, old('tag_ids', $subscriber->tags?->pluck('id')->all() ?? [])))>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <a href="{{ route('portal.marketing.subscribers.index') }}" class="btn btn-link">Cancel</a>
            <div>
                @if ($subscriber->exists)
                    <form method="POST" action="{{ route('portal.marketing.subscribers.destroy', $subscriber) }}" class="d-inline"
                          onsubmit="return confirm('Delete this subscriber permanently?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                @endif
                <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save</button>
            </div>
        </div>
    </form>
</div>
@endsection
