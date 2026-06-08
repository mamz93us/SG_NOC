@extends('layouts.admin')
@section('title', 'Azure Branch Mapping')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Azure Branch Mapping</h4>
            <p class="text-muted small mb-0">Map Azure "Office Location" keywords to system Branches</p>
        </div>
        <div class="d-flex gap-2">
            <form action="{{ route('admin.itam.azure.mappings.sync-all') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Search and update branches for ALL linked devices based on these keywords?')">
                    <i class="bi bi-pc-display me-1"></i>Sync Device Branches
                </button>
            </form>
            <form action="{{ route('admin.itam.azure.mappings.sync-employees') }}" method="POST" class="d-flex align-items-center gap-2">
                @csrf
                <div class="form-check form-switch small mb-0">
                    <input class="form-check-input" type="checkbox" name="only_unassigned" value="1" id="onlyUnassigned" checked>
                    <label class="form-check-label" for="onlyUnassigned">Only employees with no branch</label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Apply these keywords to existing employees and set their branch from their Azure office/city/department?')">
                    <i class="bi bi-people me-1"></i>Sync Employee Branches
                </button>
            </form>
            <a href="{{ route('admin.itam.azure.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Sync
            </a>
        </div>
    </div>

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-bold">Add New Mapping</div>
                <div class="card-body">
                    <form action="{{ route('admin.itam.azure.mappings.store') }}" method="POST">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Keyword</label>
                            <input type="text" name="keyword" class="form-control" placeholder="e.g. Jeddah, JED, RUH" required>
                            <div class="form-text small">Case-insensitive. Add as many keywords per branch as you like (one per row). Matched against the Azure office location, city &amp; department of devices and employees.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Map to Branch</label>
                            <select name="branch_id" class="form-select" required>
                                <option value="">-- Select Branch --</option>
                                @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Save Mapping</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Keywords by Branch</div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 30%">Branch</th>
                                <th>Keywords</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($mappings->groupBy(fn ($m) => optional($m->branch)->name ?? '— Unknown branch —') as $branchName => $items)
                            <tr>
                                <td class="fw-bold align-top">{{ $branchName }}</td>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach($items as $m)
                                        <span class="badge bg-light text-dark border d-inline-flex align-items-center gap-1">
                                            <code class="text-dark">{{ $m->keyword }}</code>
                                            <form action="{{ route('admin.itam.azure.mappings.delete', $m) }}" method="POST" onsubmit="return confirm('Remove this keyword?')" class="d-inline">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-link text-danger p-0 lh-1" title="Remove">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            </form>
                                        </span>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="2" class="text-center py-4 text-muted">No mappings defined yet.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
