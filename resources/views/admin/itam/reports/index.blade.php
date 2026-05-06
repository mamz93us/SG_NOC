@extends('layouts.admin')
@section('title', 'Asset Reports')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Asset Reports</h4>
        <a href="{{ route('admin.itam.dashboard') }}" class="btn btn-sm btn-outline-secondary">ITAM Dashboard</a>
    </div>

    <div class="row g-3 mb-4">
        @foreach([
            ['Total', 'total', 'primary'],
            ['Assigned', 'assigned', 'success'],
            ['Available', 'available', 'info'],
            ['In Branch Stores', 'in_store', 'warning'],
            ['Scrapped', 'scrapped', 'danger'],
            ['Retired', 'retired', 'secondary'],
        ] as [$label, $key, $color])
            <div class="col-6 col-md-2">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body py-3">
                        <div class="display-6 fw-bold text-{{ $color }}">{{ $stats[$key] }}</div>
                        <div class="small text-muted">{{ $label }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        @foreach([
            ['all-assets', 'bi-grid-3x3-gap', 'All Assets', 'Filterable inventory of every asset in the system.', 'primary'],
            ['by-branch', 'bi-building', 'By Branch', 'Group all assets by their assigned branch.', 'info'],
            ['by-employee', 'bi-person-badge', 'By Employee', 'View any employee\'s current and past asset assignments.', 'success'],
            ['transfers', 'bi-arrow-left-right', 'Transfer History', 'Every transfer between employees or to a branch store.', 'warning'],
            ['scraps', 'bi-trash3', 'Scrap History', 'All assets that have been formally scrapped.', 'danger'],
        ] as [$route, $icon, $title, $desc, $color])
            <div class="col-md-6 col-lg-4">
                <a href="{{ route('admin.itam.reports.' . $route) }}" class="text-decoration-none">
                    <div class="card border-0 shadow-sm h-100 report-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi {{ $icon }} text-{{ $color }} me-2" style="font-size:24px"></i>
                                <h5 class="mb-0">{{ $title }}</h5>
                            </div>
                            <p class="text-muted small mb-0">{{ $desc }}</p>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
</div>
<style>
    .report-card { transition: transform .15s, box-shadow .15s; }
    .report-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,.1) !important; }
</style>
@endsection
