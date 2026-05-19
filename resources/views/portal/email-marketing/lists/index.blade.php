@extends('layouts.portal')

@section('title', 'Lists')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('portal.marketing.lists.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus me-1"></i>New list
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th><th>Description</th><th>Subscribers</th><th>Opt-in</th><th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($lists as $list)
                    <tr>
                        <td>
                            <a href="{{ route('portal.marketing.lists.show', $list) }}"><strong>{{ $list->name }}</strong></a>
                            @if ($list->isDynamic())
                                <span class="badge bg-info text-dark ms-1" title="Membership auto-synced from the employees table">
                                    <i class="bi bi-arrow-repeat"></i> Dynamic @&#64;{{ $list->auto_domain }}
                                </span>
                            @endif
                        </td>
                        <td><small class="text-muted">{{ $list->description }}</small></td>
                        <td>{{ $list->subscribers_count }}</td>
                        <td>
                            @if ($list->double_opt_in)
                                <span class="badge bg-success">Double opt-in</span>
                            @else
                                <span class="badge bg-secondary">Single opt-in</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('portal.marketing.lists.edit', $list) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No lists yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $lists->links() }}</div>
    </div>
</div>
@endsection
