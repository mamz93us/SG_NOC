@extends('layouts.admin')

@section('content')

{{-- Page Header --}}
<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold"><i class="bi bi-gear me-2"></i>Manage Admin Links</h1>
        <small class="text-muted">Add, edit, and organize admin tool links</small>
    </div>
    <div class="ms-auto d-flex gap-2">
        <a href="{{ route('admin.admin-links.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Tools
        </a>
        <a href="{{ route('admin.admin-links.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add Link
        </a>
    </div>
</div>

{{-- Flash --}}
@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
        <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle me-1"></i>{{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="row g-4">

    {{-- Categories Panel --}}
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center">
                <h5 class="mb-0 fw-semibold"><i class="bi bi-folder me-2"></i>Categories</h5>
                <button class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
            <div class="list-group list-group-flush">
                @forelse($categories as $cat)
                <div class="list-group-item d-flex align-items-center gap-2">
                    @if($cat->icon)
                        <i class="bi bi-{{ $cat->icon }} text-primary"></i>
                    @endif
                    <div class="flex-grow-1">
                        <div class="fw-semibold small">{{ $cat->name }}</div>
                        <div class="text-muted" style="font-size:.72rem;">{{ $cat->links_count }} links | Order: {{ $cat->sort_order }}</div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                            data-bs-target="#editCategoryModal{{ $cat->id }}" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    @if($cat->links_count === 0)
                    <form method="POST" action="{{ route('admin.admin-links.categories.destroy', $cat) }}" class="d-inline"
                          onsubmit="return confirm('Delete this category?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                    @endif
                </div>

                {{-- Edit Category Modal --}}
                <div class="modal fade" id="editCategoryModal{{ $cat->id }}" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="{{ route('admin.admin-links.categories.update', $cat) }}">
                                @csrf @method('PUT')
                                <div class="modal-header">
                                    <h5 class="modal-title">Edit Category</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Name</label>
                                        <input type="text" name="name" class="form-control" value="{{ $cat->name }}" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Icon</label>
                                        <div class="input-group">
                                            <span class="input-group-text">bi-</span>
                                            <input type="text" name="icon" class="form-control" value="{{ $cat->icon }}">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Sort Order</label>
                                        <input type="number" name="sort_order" class="form-control" value="{{ $cat->sort_order }}" min="0">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                @empty
                <div class="list-group-item text-muted text-center py-3">
                    <i class="bi bi-folder2-open me-1"></i>No categories yet
                </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Links Table --}}
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center">
                <h5 class="mb-0 fw-semibold"><i class="bi bi-link-45deg me-2"></i>Links</h5>
                <a href="{{ route('admin.admin-links.create') }}" class="btn btn-sm btn-primary ms-auto">
                    <i class="bi bi-plus-lg me-1"></i>Add
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="small fw-semibold">Name</th>
                            <th class="small fw-semibold">Category</th>
                            <th class="small fw-semibold">URL</th>
                            <th class="small fw-semibold text-center">Status</th>
                            <th class="small fw-semibold text-center">Clicks</th>
                            <th class="small fw-semibold text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($links as $link)
                        <tr>
                            <td>
                                @if($link->icon)
                                    <i class="bi bi-{{ $link->icon }} me-1 text-primary"></i>
                                @endif
                                <span class="fw-semibold small">{{ $link->name }}</span>
                                @if($link->description)
                                    <br><span class="text-muted" style="font-size:.72rem;">{{ Str::limit($link->description, 60) }}</span>
                                @endif
                            </td>
                            <td class="small">{{ $link->category->name ?? '-' }}</td>
                            <td>
                                <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer" class="text-decoration-none small font-monospace">
                                    {{ Str::limit($link->url, 35) }}
                                </a>
                            </td>
                            <td class="text-center">
                                @if($link->is_active)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Disabled</span>
                                @endif
                            </td>
                            <td class="text-center small">{{ $link->clicks_count ?? $link->clicks->count() }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.admin-links.edit', $link) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="{{ route('admin.admin-links.destroy', $link) }}" class="d-inline"
                                      onsubmit="return confirm('Delete this link?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="bi bi-link-45deg me-1"></i>No links yet.
                                <a href="{{ route('admin.admin-links.create') }}">Add one</a>.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($links->hasPages())
            <div class="card-footer bg-white">
                {{ $links->links() }}
            </div>
            @endif
        </div>
    </div>
</div>

{{-- Add Category Modal --}}
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.admin-links.categories.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Microsoft Services">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Icon</label>
                        <div class="input-group">
                            <span class="input-group-text">bi-</span>
                            <input type="text" name="icon" class="form-control" placeholder="e.g. microsoft, globe">
                        </div>
                        <small class="text-muted">Bootstrap Icons name without bi- prefix</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
