@extends('layouts.portal')

@section('title', 'Tags')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('portal.marketing.tags.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus me-1"></i>New tag
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Name</th><th>Color</th><th>Subscribers</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($tags as $tag)
                    <tr>
                        <td><span class="badge" style="background-color: {{ $tag->color ?: '#6c757d' }}">{{ $tag->name }}</span></td>
                        <td><small><code>{{ $tag->color ?: '—' }}</code></small></td>
                        <td>{{ $tag->subscribers_count }}</td>
                        <td class="text-end">
                            <a href="{{ route('portal.marketing.tags.edit', $tag) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="{{ route('portal.marketing.tags.destroy', $tag) }}" class="d-inline" onsubmit="return confirm('Delete tag?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">No tags yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $tags->links() }}</div>
    </div>
</div>
@endsection
