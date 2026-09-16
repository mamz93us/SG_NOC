@extends('layouts.admin')
@section('title', 'Microsoft 365 License Review')

@section('content')
@php
    $money = fn (array $costs) => $costs
        ? implode(' · ', array_map(fn ($currency, $amount) => $currency.' '.number_format($amount, 0), array_keys($costs), $costs))
        : '—';
    $filterUrl = fn (?string $to) => route('admin.itam.reports.microsoft-licenses', array_filter(['category' => $to, 'q' => $search]));
    $cards = array_filter(
        $categories,
        fn ($meta, $key) => $key !== 'no_employee' || $review['summary'][$key]['count'] > 0,
        ARRAY_FILTER_USE_BOTH
    );
@endphp
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-microsoft me-2 text-primary"></i>Microsoft 365 License Review</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.itam.reports.microsoft-licenses', array_filter(['category' => $category, 'q' => $search, 'csv' => 1])) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <a href="{{ route('admin.itam.reports.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>

    <p class="text-muted small mb-1">
        Every Azure account holding a paid Microsoft license, sorted by whether a real employee needs it.
        Rooms and app accounts are in the employee list too, so a matching employee record is not proof of a person:
        the Oracle HR number is. Free licenses (Power Automate Free, Power BI free) are left out.
    </p>
    <p class="text-muted small mb-3">
        Licenses synced from Microsoft {{ $review['licenses_synced_at']?->diffForHumans() ?? '— not yet' }}.
        Accounts and names from the identity sync {{ $review['accounts_synced_at']?->diffForHumans() ?? '— not yet' }}.
        Cost is the per-seat price entered on each license in
        @can('view-licenses')<a href="{{ route('admin.itam.licenses.index') }}">ITAM ▸ Licenses</a>@else ITAM ▸ Licenses @endcan.
    </p>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-xl">
            <a href="{{ $filterUrl(null) }}" class="text-decoration-none">
                <div class="card shadow-sm h-100 {{ $category === null ? 'border-primary border-2' : 'border-0' }}">
                    <div class="card-body py-3">
                        <div class="display-6 fw-bold text-body">{{ $review['total']['count'] }}</div>
                        <div class="small fw-semibold text-body">All licensed accounts</div>
                        <div class="small text-muted font-monospace">{{ $money($review['total']['costs']) }}</div>
                    </div>
                </div>
            </a>
        </div>
        @foreach($cards as $key => $meta)
            <div class="col-6 col-md-4 col-xl">
                <a href="{{ $filterUrl($key) }}" class="text-decoration-none">
                    <div class="card shadow-sm h-100 {{ $category === $key ? 'border-'.$meta['color'].' border-2' : 'border-0' }}">
                        <div class="card-body py-3">
                            <div class="display-6 fw-bold text-{{ $meta['color'] }}">{{ $review['summary'][$key]['count'] }}</div>
                            <div class="small fw-semibold text-body">{{ $meta['title'] }}</div>
                            <div class="small text-muted font-monospace">{{ $money($review['summary'][$key]['costs']) }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    @if($category)
        <div class="alert alert-light border small py-2 mb-3">
            <span class="badge text-bg-{{ $categories[$category]['color'] }} me-1">{{ $categories[$category]['label'] }}</span>
            {{ $categories[$category]['hint'] }}
        </div>
    @endif

    <form method="GET" action="{{ route('admin.itam.reports.microsoft-licenses') }}" class="row g-2 align-items-center mb-3 d-print-none">
        @if($category)<input type="hidden" name="category" value="{{ $category }}">@endif
        <div class="col-md-4">
            <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search account, name or employee">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Search</button>
        </div>
        @if($search !== '' || $category)
            <div class="col-auto">
                <a href="{{ route('admin.itam.reports.microsoft-licenses') }}" class="btn btn-sm btn-link">Clear filters</a>
            </div>
        @endif
        <div class="col text-end small text-muted">{{ $rows->total() }} {{ \Illuminate\Support\Str::plural('account', $rows->total()) }}</div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Account</th>
                        <th>NOC employee</th>
                        <th>Category</th>
                        <th class="text-nowrap">Oracle HR no.</th>
                        @if($canSeeAttendance)<th class="text-nowrap">Last punch</th>@endif
                        <th>Paid licenses</th>
                        <th class="text-end">Cost</th>
                        <th style="min-width: 280px">Why</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($rows as $row)
                    @php
                        $meta = $categories[$row['category']];
                        $employee = $row['employee'];
                    @endphp
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $row['display_name'] }}</div>
                            <div class="small text-muted">{{ $row['upn'] }}</div>
                            @unless($row['enabled'])
                                <span class="badge text-bg-danger">Sign-in disabled</span>
                            @endunless
                        </td>
                        <td>
                            @if($employee)
                                <a href="{{ route('admin.employees.show', $employee['id']) }}">{{ $employee['name'] }}</a>
                                @if(($employee['status'] ?? 'active') !== 'active')
                                    <span class="badge text-bg-secondary">{{ ucfirst(str_replace('_', ' ', $employee['status'])) }}</span>
                                @endif
                                @if(($employee['employee_type'] ?? 'standard') !== 'standard')
                                    <span class="badge text-bg-light border">{{ ucfirst($employee['employee_type']) }}</span>
                                @endif
                                @if(! empty($employee['branch']))
                                    <div class="small text-muted">{{ $employee['branch'] }}</div>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td><span class="badge text-bg-{{ $meta['color'] }}">{{ $meta['label'] }}</span></td>
                        <td class="small">
                            <span class="font-monospace">{{ $row['oracle_emp_no'] ?? '—' }}</span>
                            @if($row['oracle_on_record'])
                                <div class="text-muted text-nowrap">on <a href="{{ route('admin.employees.show', $row['oracle_on_record']) }}">record #{{ $row['oracle_on_record'] }}</a></div>
                            @endif
                        </td>
                        @if($canSeeAttendance)
                            <td class="small text-nowrap">{{ $row['last_punch'] ? \Illuminate\Support\Carbon::parse($row['last_punch'])->format('d M Y') : '—' }}</td>
                        @endif
                        <td class="small">{{ implode(', ', array_column($row['paid'], 'name')) }}</td>
                        <td class="text-end small font-monospace text-nowrap">{{ $money($row['costs']) }}</td>
                        <td class="small text-muted">{{ $row['reason'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canSeeAttendance ? 8 : 7 }}" class="text-center py-5 text-muted">No licensed accounts match.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
</div>
@endsection
