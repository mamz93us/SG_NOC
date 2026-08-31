@extends('layouts.admin')

@section('title', 'Announcements')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-megaphone-fill me-2 text-primary"></i>Announcements</h4>
        <small class="text-muted">
            What the company sees on the employee home portal &mdash; the slider shows the newest few.
        </small>
    </div>
    <a href="{{ route('admin.announcements.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>New Announcement
    </a>
</div>

@if (session('success'))
    <div class="alert alert-success d-flex gap-2 align-items-start">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div>{{ session('success') }}</div>
    </div>
@endif

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th style="width:110px">Severity</th>
                    <th style="min-width:150px">Audience</th>
                    <th style="width:130px">Published</th>
                    <th style="width:130px">Expires</th>
                    <th style="width:110px" class="text-center">Status</th>
                    <th style="width:120px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($announcements as $ann)
                    <tr>
                        <td>
                            <div class="fw-semibold">
                                @if($ann->pinned)
                                    <i class="bi bi-pin-angle-fill text-warning me-1" title="Pinned"></i>
                                @endif
                                {{ $ann->title }}
                            </div>
                            <div class="small text-muted text-truncate" style="max-width:420px">
                                {{ $ann->excerpt(110) }}
                            </div>
                        </td>
                        <td>
                            <span class="badge {{ $ann->severity === 'urgent' ? 'bg-danger' : ($ann->severity === 'success' ? 'bg-success' : 'bg-secondary') }}">
                                {{ ucfirst($ann->severity) }}
                            </span>
                        </td>
                        <td class="small">
                            @if($ann->audience === 'branch')
                                Branch: {{ $ann->branch?->name ?? '—' }}
                            @elseif($ann->audience === 'department')
                                Dept: {{ $ann->department?->name ?? '—' }}
                            @else
                                Everyone
                            @endif
                        </td>
                        <td class="small text-muted">{{ $ann->published_at?->format('d M Y') ?: '—' }}</td>
                        <td class="small text-muted">{{ $ann->expires_at?->format('d M Y') ?: 'Never' }}</td>
                        <td class="text-center">
                            @if(! $ann->is_published)
                                <span class="badge bg-secondary">Draft</span>
                            @elseif($ann->expires_at && $ann->expires_at->isPast())
                                <span class="badge bg-dark">Expired</span>
                            @elseif($ann->published_at && $ann->published_at->isFuture())
                                <span class="badge bg-info text-dark">Scheduled</span>
                            @else
                                <span class="badge bg-success">Live</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.announcements.edit', $ann) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="{{ route('admin.announcements.destroy', $ann) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete “{{ addslashes($ann->title) }}”? This cannot be undone.');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-megaphone fs-3 d-block mb-2"></i>
                            No announcements yet. The slider stays hidden until there is something to show.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($announcements->hasPages())
    <div class="mt-3">{{ $announcements->links() }}</div>
@endif
@endsection
