@extends('layouts.admin')
@section('title', 'Azure Branch Mapping')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Azure Branch Mapping</h4>
            <p class="text-muted small mb-0">Map Azure "Office Location" keywords to system Branches</p>
        </div>
        <a href="{{ route('admin.itam.azure.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Sync
        </a>
    </div>

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif

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
                            <div class="form-text small">Case-insensitive. System will search displays name and office location.</div>
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
                <div class="card-header bg-white fw-bold">Current Mappings</div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Keyword</th>
                                <th>Branch</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($mappings as $m)
                            <tr>
                                <td class="fw-bold"><code>{{ $m->keyword }}</code></td>
                                <td>{{ $m->branch->name }}</td>
                                <td class="text-end">
                                    <form action="{{ route('admin.itam.azure.mappings.delete', $m) }}" method="POST" onsubmit="return confirm('Remove this mapping?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" class="text-center py-4 text-muted">No mappings defined yet.</td>
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
