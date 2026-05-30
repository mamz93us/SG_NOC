@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-briefcase me-2 text-primary"></i>Jobs</h4>
        <small class="text-muted">
            @if($configured && !$error)
                {{ number_format($total) }} job{{ $total === 1 ? '' : 's' }} from Teamtailor
            @else
                Teamtailor recruitment
            @endif
        </small>
    </div>
    <a href="{{ route('admin.candidates.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-person-rolodex me-1"></i>All candidates
    </a>
</div>

@unless($configured)
<div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div>
        <strong>Teamtailor is not configured.</strong>
        Set an Admin-scoped API token under <a href="{{ route('admin.settings.index') }}#teamtailor">Settings → Teamtailor</a>.
    </div>
</div>
@endunless

@if($error)
<div class="alert alert-danger d-flex align-items-start gap-2">
    <i class="bi bi-x-octagon-fill mt-1"></i>
    <div>
        <strong>Could not load jobs.</strong>
        <div class="small">{{ $error }}</div>
    </div>
</div>
@endif

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($jobs->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-briefcase display-4 d-block mb-2"></i>
            @if($configured && !$error)
                No jobs to show.
            @else
                No jobs to show.
            @endif
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Job</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="pe-3 text-end">Applicants</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($jobs as $j)
                    <tr>
                        <td class="ps-3 fw-semibold">{{ $j['title'] }}</td>
                        <td>
                            @php $st = strtolower((string) $j['status']); @endphp
                            @if($st === 'open' || $st === 'published')
                                <span class="badge bg-success-subtle text-success border border-success-subtle">{{ ucfirst($st) }}</span>
                            @elseif($st === 'draft' || $st === 'unlisted')
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">{{ ucfirst($st) }}</span>
                            @elseif($st === 'archived')
                                <span class="badge bg-dark-subtle text-dark border">{{ ucfirst($st) }}</span>
                            @elseif($st)
                                <span class="badge bg-light text-dark border">{{ ucfirst($st) }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $j['created_at'] ? \Illuminate\Support\Carbon::parse($j['created_at'])->format('d M Y') : '—' }}</td>
                        <td class="pe-3 text-end">
                            <a href="{{ route('admin.jobs.show', ['job' => $j['id'], 'title' => $j['title']]) }}"
                               class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-people me-1"></i>View applicants
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted">Showing {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ number_format($paginator->total()) }}</small>
            {{ $paginator->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
