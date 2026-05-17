@extends('layouts.admin')

@section('title', 'Email Marketing — Suppressions')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="bi bi-shield-x me-2"></i>Email Marketing — Global Suppressions</h3>
        <a href="{{ route('admin.email-marketing.settings') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Settings
        </a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">
            @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
        </ul></div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <form class="card shadow-sm" method="POST" action="{{ route('admin.email-marketing.suppressions.store') }}">
                @csrf
                <div class="card-body">
                    <h6 class="mb-3">Add to suppression list</h6>
                    <div class="row g-2">
                        <div class="col-md-7">
                            <input type="email" name="email" class="form-control" placeholder="bad@example.com" required>
                        </div>
                        <div class="col-md-5">
                            <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-danger btn-sm mt-2">
                        <i class="bi bi-shield-plus me-1"></i>Add
                    </button>
                </div>
            </form>
        </div>
        <div class="col-md-6">
            <form class="card shadow-sm" method="POST" action="{{ route('admin.email-marketing.suppressions.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="card-body">
                    <h6 class="mb-3">Bulk import (CSV — one email per line)</h6>
                    <input type="file" name="file" class="form-control" accept=".csv,.txt" required>
                    <button type="submit" class="btn btn-outline-danger btn-sm mt-2">
                        <i class="bi bi-upload me-1"></i>Import
                    </button>
                </div>
            </form>
        </div>
    </div>

    <form class="row g-2 mb-3" method="GET">
        <div class="col-md-4">
            <input type="text" name="q" class="form-control" placeholder="Search email…" value="{{ $q }}">
        </div>
        <div class="col-md-3">
            <select name="reason" class="form-select">
                <option value="">All reasons</option>
                @foreach (['hard_bounce','complaint','manual','sns_suppression_list'] as $r)
                    <option value="{{ $r }}" @selected($reason === $r)>{{ $r }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-primary w-100">Filter</button>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Email</th>
                        <th>Reason</th>
                        <th>Source</th>
                        <th>Added</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($suppressions as $s)
                    <tr>
                        <td><code>{{ $s->email }}</code></td>
                        <td>
                            <span class="badge bg-{{ $s->reason === 'hard_bounce' ? 'danger' : ($s->reason === 'complaint' ? 'warning' : 'secondary') }}">
                                {{ $s->reason }}
                            </span>
                        </td>
                        <td><small>{{ $s->source ?: '—' }}</small></td>
                        <td><small>{{ $s->created_at?->diffForHumans() }}</small></td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('admin.email-marketing.suppressions.destroy', $s) }}" class="d-inline"
                                  onsubmit="return confirm('Remove {{ $s->email }} from suppression list?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No suppressed addresses.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $suppressions->links() }}</div>
    </div>
</div>
@endsection
