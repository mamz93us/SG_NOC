@extends('layouts.admin')
@section('title', 'Branch Stores')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-box-seam me-2"></i>Asset Stores</h4>
        <div class="d-flex gap-2">
            @can('manage-itam')
                <a href="{{ route('admin.itam.transfer.index') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-arrow-left-right me-1"></i>Transfer
                </a>
            @endcan
            <a href="{{ route('admin.itam.dashboard') }}" class="btn btn-sm btn-outline-secondary">ITAM Dashboard</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-3 text-center">
                    <div class="display-6 fw-bold text-info">{{ $stats['total_in_storage'] }}</div>
                    <div class="small text-muted">Total Unassigned (in stores)</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-3 text-center">
                    <div class="display-6 fw-bold text-primary">{{ $stats['universal_count'] }}</div>
                    <div class="small text-muted">In Universal Store</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-3 text-center">
                    <div class="display-6 fw-bold text-success">{{ $stats['branches_with_stock'] }}</div>
                    <div class="small text-muted">Branches with Stock</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-3 text-center">
                    <div class="display-6 fw-bold text-warning">{{ $stats['unlinked_intune'] }}</div>
                    <div class="small text-muted">Unlinked Intune Devices</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Universal Store --}}
    <div class="card border-0 shadow-sm mb-3 border-start border-4 border-primary">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1">
                    <i class="bi bi-globe me-2 text-primary"></i>Universal Store
                    <span class="badge bg-primary ms-2">{{ $universalCount }}</span>
                    @if($unlinkedIntuneCount > 0)
                        <span class="badge bg-warning text-dark ms-1">+{{ $unlinkedIntuneCount }} unlinked Intune</span>
                    @endif
                </h5>
                <small class="text-muted">Unassigned assets without a branch + unlinked Intune devices awaiting import.</small>
            </div>
            <a href="{{ route('admin.itam.stores.universal') }}" class="btn btn-primary">
                <i class="bi bi-eye me-1"></i>Open Universal Store
            </a>
        </div>
    </div>

    {{-- Branch Stores --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><strong>Branch Stores</strong></div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Branch</th>
                        <th class="text-end">Unassigned Assets</th>
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
