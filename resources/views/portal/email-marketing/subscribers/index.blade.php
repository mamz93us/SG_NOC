@extends('layouts.portal')

@section('title', 'Subscribers')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <form class="row g-2 mb-3" method="GET">
        <div class="col-md-4">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search email or name…" value="{{ $q }}">
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select form-select-sm">
                <option value="">All statuses</option>
                @foreach (['subscribed','pending','unsubscribed','bounced','complained'] as $st)
                    <option value="{{ $st }}" @selected($status === $st)>{{ $st }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select name="list_id" class="form-select form-select-sm">
                <option value="">All lists</option>
                @foreach ($lists as $l)
                    <option value="{{ $l->id }}" @selected($listId === $l->id)>{{ $l->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-primary btn-sm w-100">Filter</button>
        </div>
        <div class="col-md-1 text-end">
            <a href="{{ route('portal.marketing.subscribers.create') }}" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus"></i></a>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Email</th><th>Name</th><th>Status</th><th>Tags</th><th>Source</th><th>Added</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($subscribers as $s)
                    <tr>
                        <td><a href="{{ route('portal.marketing.subscribers.edit', $s) }}">{{ $s->email }}</a></td>
                        <td>{{ trim(($s->first_name ?? '').' '.($s->last_name ?? '')) }}</td>
                        <td>
                            <span class="badge bg-{{ $s->status === 'subscribed' ? 'success' : ($s->status === 'pending' ? 'warning' : 'secondary') }}">
                                {{ $s->status }}
                            </span>
                        </td>
                        <td>
                            @foreach ($s->tags as $tag)
                                <span class="badge" style="background-color: {{ $tag->color ?: '#6c757d' }}">{{ $tag->name }}</span>
                            @endforeach
                        </td>
                        <td><small>{{ $s->source ?: '—' }}</small></td>
                        <td><small>{{ $s->created_at?->diffForHumans() }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No subscribers match.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $subscribers->links() }}</div>
    </div>
</div>
@endsection
