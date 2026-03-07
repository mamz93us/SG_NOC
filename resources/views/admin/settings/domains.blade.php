@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-globe me-2 text-primary"></i>Allowed Domains</h4>
        <small class="text-muted">Organizational email domains — used to filter external/guest Azure AD users during sync</small>
    </div>
    <a href="{{ route('admin.settings.index') }}#domains" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Settings
    </a>
</div>


{{-- Current Domains --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
        <strong><i class="bi bi-list-check me-1"></i>Registered Domains</strong>
        <span class="badge bg-secondary">{{ $domains->count() }} domain{{ $domains->count() !== 1 ? 's' : '' }}</span>
    </div>
    <div class="card-body p-0">
        @if($domains->isEmpty())
            <div class="text-center text-muted py-4">No domains configured yet. Add one below.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Domain</th>
                        <th>Description</th>
                        <th class="text-center">Primary</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($domains as $domain)
                    <tr>
                        <td class="ps-3">
                            <strong>{{ $domain->domain }}</strong>
                            @if($domain->is_primary)
                                <span class="badge bg-warning text-dark ms-1"><i class="bi bi-star-fill me-1"></i>Primary</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $domain->description ?: '—' }}</td>
                        <td class="text-center">
                            @if(!$domain->is_primary)
                            <form method="POST" action="{{ route('admin.settings.domains.set-primary', $domain->id) }}" class="d-inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Set as primary">
                                    <i class="bi bi-star me-1"></i>Set Primary
                                </button>
                            </form>
                            @else
                                <span class="text-warning"><i class="bi bi-star-fill"></i></span>
                            @endif
                        </td>
                        <td class="text-end pe-3">
                            <form method="POST" action="{{ route('admin.settings.domains.destroy', $domain->id) }}" class="d-inline"
                                  onsubmit="return confirm('Remove domain {{ $domain->domain }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i> Remove
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

{{-- Add Domain --}}
<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-plus-circle me-1"></i>Add Domain</strong>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.settings.domains.store') }}">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Domain <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text text-muted">@</span>
                        <input type="text" name="domain" class="form-control @error('domain') is-invalid @enderror"
                               value="{{ old('domain') }}" placeholder="samirgroup.com" required>
                    </div>
                    @error('domain') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Description</label>
                    <input type="text" name="description" class="form-control"
                           value="{{ old('description') }}" placeholder="Optional label">
                </div>
                <div class="col-md-2 d-flex align-items-end pb-1">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_primary" value="1" id="isPrimary">
                        <label class="form-check-label fw-semibold" for="isPrimary">Set as Primary</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-plus me-1"></i>Add Domain
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

@endsection
