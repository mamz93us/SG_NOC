@extends('layouts.portal')

@section('title', 'Segments')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('portal.marketing.segments.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus me-1"></i>New segment
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr><th>Name</th><th>Rules</th><th></th></tr></thead>
                <tbody>
                @forelse ($segments as $s)
                    <tr>
                        <td><a href="{{ route('portal.marketing.segments.edit', $s) }}">{{ $s->name }}</a></td>
                        <td><small class="text-muted"><code>{{ json_encode($s->rules) }}</code></small></td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('portal.marketing.segments.destroy', $s) }}" class="d-inline"
                                  onsubmit="return confirm('Delete segment?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-center text-muted py-4">No segments yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $segments->links() }}</div>
    </div>
</div>
@endsection
