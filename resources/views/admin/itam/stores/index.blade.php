@extends('layouts.admin')
@section('title', 'Branch Stores')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-box-seam me-2"></i>Branch Stores</h4>
        <a href="{{ route('admin.itam.dashboard') }}" class="btn btn-sm btn-outline-secondary">ITAM Dashboard</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-3 text-center">
                    <div class="display-6 fw-bold text-info">{{ $stats['total_in_storage'] }}</div>
                    <div class="small text-muted">Total Assets in Storage</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-3 text-center">
                    <div class="display-6 fw-bold text-primary">{{ $stats['branches_with_stock'] }}</div>
                    <div class="small text-muted">Branches with Stock</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-3 text-center">
                    <div class="display-6 fw-bold text-secondary">{{ $branches->count() }}</div>
                    <div class="small text-muted">Total Branches</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><strong>Branches</strong></div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Branch</th>
                        <th class="text-end">Assets in Store</th>
                        <th>Last Activity</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($branches as $branch)
                        @php($entry = $countsByBranch[$branch->id] ?? null)
                        <tr>
                            <td><strong>{{ $branch->name }}</strong></td>
                            <td class="text-end">
                                @if($entry && $entry->c > 0)
                                    <span class="badge bg-info">{{ $entry->c }}</span>
                                @else
                                    <span class="text-muted">0</span>
                                @endif
                            </td>
                            <td>{{ $entry?->last_activity ? \Carbon\Carbon::parse($entry->last_activity)->diffForHumans() : '—' }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.itam.stores.show', $branch->id) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye me-1"></i>View Store
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
