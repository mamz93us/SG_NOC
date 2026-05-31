@extends('layouts.marketing')

@section('title', $list->name)

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    @if ($list->isDynamic())
        <div class="alert alert-info d-flex align-items-start mb-3">
            <i class="bi bi-arrow-repeat me-2 mt-1"></i>
            <div>
                <strong>Dynamic list.</strong>
                Membership is auto-synced from active employees whose email ends with
                <code>&#64;{{ $list->auto_domain }}</code>. New employees are added automatically;
                terminated employees are removed. Manual subscriber edits made here will be
                overwritten by the next sync.
            </div>
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">{{ $list->name }} <small class="text-muted">({{ $list->subscribers_count }} subscribers)</small></h4>
        <div>
            @unless ($list->isDynamic())
                <a href="{{ route('portal.marketing.subscribers.import.form') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-upload me-1"></i>Import CSV
                </a>
            @endunless
            <a href="{{ route('portal.marketing.lists.export', $list) }}" class="btn btn-outline-success btn-sm">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
            <a href="{{ route('portal.marketing.lists.edit', $list) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            @unless ($list->isDynamic())
                <form method="POST" action="{{ route('portal.marketing.lists.destroy', $list) }}" class="d-inline"
                      onsubmit="return confirm('Delete this list? Subscribers are kept.')">
                    @csrf @method('DELETE')
                    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                </form>
            @endunless
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Email</th><th>Name</th><th>Status</th><th>Joined</th>
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
                        <td><small>{{ optional($s->pivot->subscribed_at)?->diffForHumans() ?: 'pending' }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">No subscribers in this list yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $subscribers->links() }}</div>
    </div>
</div>
@endsection
