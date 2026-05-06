@extends('layouts.admin')

@section('title', 'Edit Branch — ' . $collector->name)

@section('content')
<div class="container-fluid py-3">
    <h4 class="mb-3">Edit branch — <code>{{ $collector->code }}</code></h4>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('admin.branches.log-collectors.update', $collector) }}" method="POST">
                @method('PUT')
                @include('admin.branches.log-collectors._form')
            </form>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-body small">
            <div class="row">
                <div class="col-md-3"><strong>Last health</strong></div>
                <div class="col-md-9">@include('admin.branches.log-collectors._status', ['c' => $collector])</div>

                <div class="col-md-3 mt-2"><strong>Last seen</strong></div>
                <div class="col-md-9 mt-2">{{ $collector->last_seen_at?->toDateTimeString() ?? '—' }}</div>

                @if($collector->last_error)
                    <div class="col-md-3 mt-2"><strong>Last error</strong></div>
                    <div class="col-md-9 mt-2 text-danger font-monospace small">{{ $collector->last_error }}</div>
                @endif

                <div class="col-md-3 mt-2"><strong>Created</strong></div>
                <div class="col-md-9 mt-2">{{ $collector->created_at?->toDateTimeString() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
