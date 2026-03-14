@extends('layouts.admin')

@section('content')

{{-- Page Header --}}
<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold"><i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i>Admin Tools</h1>
        <small class="text-muted">Quick access to administration portals and tools</small>
    </div>
    @can('manage-admin-links')
    <div class="ms-auto">
        <a href="{{ route('admin.admin-links.manage') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Manage Links
        </a>
    </div>
    @endcan
</div>

{{-- Search & Filter --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('admin.admin-links.index') }}" class="row g-2 align-items-end">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search admin tools..."
                           value="{{ $search }}">
                </div>
            </div>
            <div class="col-md-4">
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ $categoryFilter == $cat->id ? 'selected' : '' }}>
                            {{ $cat->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                @if($search || $categoryFilter)
                    <a href="{{ route('admin.admin-links.index') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-x-lg"></i>
                    </a>
                @endif
            </div>
        </form>
    </div>
</div>

{{-- Flash --}}
@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
        <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Favorites Section --}}
@if($favorites->count() > 0)
<div class="mb-4">
    <h5 class="fw-semibold mb-3"><i class="bi bi-star-fill me-2 text-warning"></i>My Favorites</h5>
    <div class="row g-3">
        @foreach($favorites as $link)
        <div class="col-6 col-md-4 col-lg-3 col-xl-2">
            <div class="card border-0 shadow-sm h-100 position-relative">
                <div class="card-body text-center py-3 px-2">
                    <form method="POST" action="{{ route('admin.admin-links.favorite', $link) }}" class="position-absolute" style="top:6px;right:6px;">
                        @csrf
                        <button type="submit" class="btn btn-link btn-sm p-0 text-warning" title="Remove from favorites">
                            <i class="bi bi-star-fill"></i>
                        </button>
                    </form>
                    @if($link->icon)
                        <i class="bi bi-{{ $link->icon }} fs-2 text-primary d-block mb-2"></i>
                    @else
                        <i class="bi bi-box-arrow-up-right fs-2 text-primary d-block mb-2"></i>
                    @endif
                    <h6 class="mb-1 small fw-semibold">{{ $link->name }}</h6>
                    @if($link->description)
                        <p class="text-muted mb-2" style="font-size:.72rem;">{{ Str::limit($link->description, 50) }}</p>
                    @endif
                    <a href="{{ route('admin.admin-links.go', $link) }}" target="_blank" rel="noopener noreferrer"
                       class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open
                    </a>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- Most Used Section --}}
@if($topLinks->count() > 0 && !$search && !$categoryFilter)
<div class="mb-4">
    <h5 class="fw-semibold mb-3"><i class="bi bi-fire me-2 text-danger"></i>Most Used</h5>
    <div class="d-flex flex-wrap gap-2">
        @foreach($topLinks as $topClick)
            @if($topClick->link && $topClick->link->is_active)
            <a href="{{ route('admin.admin-links.go', $topClick->link) }}" target="_blank" rel="noopener noreferrer"
               class="btn btn-sm btn-outline-dark">
                @if($topClick->link->icon)
                    <i class="bi bi-{{ $topClick->link->icon }} me-1"></i>
                @endif
                {{ $topClick->link->name }}
                <span class="badge bg-secondary ms-1">{{ $topClick->click_count }}</span>
            </a>
            @endif
        @endforeach
    </div>
</div>
@endif

{{-- Links by Category --}}
@forelse($links as $categoryId => $categoryLinks)
    @php $category = $categories->firstWhere('id', $categoryId); @endphp
    @if($category)
    <div class="mb-4">
        <h5 class="fw-semibold mb-3">
            @if($category->icon)
                <i class="bi bi-{{ $category->icon }} me-2 text-primary"></i>
            @endif
            {{ $category->name }}
        </h5>
        <div class="row g-3">
            @foreach($categoryLinks as $link)
            <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                <div class="card border-0 shadow-sm h-100 position-relative">
                    <div class="card-body text-center py-3 px-2">
                        <form method="POST" action="{{ route('admin.admin-links.favorite', $link) }}" class="position-absolute" style="top:6px;right:6px;">
                            @csrf
                            <button type="submit" class="btn btn-link btn-sm p-0 {{ in_array($link->id, $favoriteIds) ? 'text-warning' : 'text-muted' }}"
                                    title="{{ in_array($link->id, $favoriteIds) ? 'Remove from favorites' : 'Add to favorites' }}">
                                <i class="bi bi-star{{ in_array($link->id, $favoriteIds) ? '-fill' : '' }}"></i>
                            </button>
                        </form>
                        @if($link->icon)
                            <i class="bi bi-{{ $link->icon }} fs-2 text-primary d-block mb-2"></i>
                        @else
                            <i class="bi bi-box-arrow-up-right fs-2 text-primary d-block mb-2"></i>
                        @endif
                        <h6 class="mb-1 small fw-semibold">{{ $link->name }}</h6>
                        @if($link->description)
                            <p class="text-muted mb-2" style="font-size:.72rem;">{{ Str::limit($link->description, 50) }}</p>
                        @endif
                        <a href="{{ route('admin.admin-links.go', $link) }}" target="_blank" rel="noopener noreferrer"
                           class="btn btn-sm btn-primary w-100">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Open
                        </a>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif
@empty
    <div class="alert alert-info border-0 shadow-sm">
        <i class="bi bi-info-circle me-2"></i>
        @if($search || $categoryFilter)
            No admin tools match your search criteria.
        @else
            No admin tools configured yet.
            @can('manage-admin-links')
                <a href="{{ route('admin.admin-links.manage') }}" class="alert-link">Add some in Manage Links</a>.
            @endcan
        @endif
    </div>
@endforelse

@endsection
