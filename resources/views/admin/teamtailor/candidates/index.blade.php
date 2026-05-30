@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-person-rolodex me-2 text-primary"></i>Candidates</h4>
        <small class="text-muted">
            @if($configured && !$error)
                {{ number_format($total) }} candidate{{ $total === 1 ? '' : 's' }} from Teamtailor
            @else
                Teamtailor recruitment
            @endif
        </small>
    </div>
</div>

@unless($configured)
<div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div>
        <strong>Teamtailor is not configured.</strong>
        Set <code>TEAMTAILOR_API_KEY</code> (an Admin-scoped token) in your <code>.env</code> and run
        <code>php artisan config:clear</code>. Optionally set <code>TEAMTAILOR_BASE_URL</code>
        (<code>https://api.teamtailor.com</code> for EU, <code>https://api.na.teamtailor.com</code> for NA).
    </div>
</div>
@endunless

@if($error)
<div class="alert alert-danger d-flex align-items-start gap-2">
    <i class="bi bi-x-octagon-fill mt-1"></i>
    <div>
        <strong>Could not load candidates.</strong>
        <div class="small">{{ $error }}</div>
    </div>
</div>
@endif

{{-- Search & Filters --}}
<form method="GET" class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Email</label>
                <input type="text" name="email" class="form-control form-control-sm"
                       placeholder="name@example.com" value="{{ request('email') }}" autocomplete="off">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Phone</label>
                <input type="text" name="phone" class="form-control form-control-sm"
                       placeholder="+20…" value="{{ request('phone') }}" autocomplete="off">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Connected</label>
                <select name="connected" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="true"  {{ request('connected') === 'true'  ? 'selected' : '' }}>Connected</option>
                    <option value="false" {{ request('connected') === 'false' ? 'selected' : '' }}>Not connected</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Created from</label>
                <input type="date" name="created_from" class="form-control form-control-sm" value="{{ request('created_from') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Created to</label>
                <input type="date" name="created_to" class="form-control form-control-sm" value="{{ request('created_to') }}">
            </div>
            <div class="col-md-1">
                <label class="form-label small text-muted mb-1">Sort</label>
                <select name="sort" class="form-select form-select-sm">
                    <option value="newest" {{ request('sort') !== 'oldest' ? 'selected' : '' }}>Newest</option>
                    <option value="oldest" {{ request('sort') === 'oldest' ? 'selected' : '' }}>Oldest</option>
                </select>
            </div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-search me-1"></i>Filter
            </button>
            @if(request()->anyFilled(['email','phone','connected','created_from','created_to']) || request('sort') === 'oldest')
            <a href="{{ route('admin.candidates.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-x-lg me-1"></i>Clear
            </a>
            @endif
        </div>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($candidates->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-person-rolodex display-4 d-block mb-2"></i>
            @if($configured && !$error)
                No candidates match your filters.
            @else
                No candidates to show.
            @endif
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Candidate</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Tags</th>
                        <th>Created</th>
                        <th class="pe-3 text-end">Links</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($candidates as $c)
                    <tr>
                        <td class="ps-3">
                            <div class="fw-semibold">{{ $c['name'] }}</div>
                            @if($c['email'])
                            <div class="text-muted" style="font-size:.75rem">
                                <a href="mailto:{{ $c['email'] }}" class="text-decoration-none text-muted">{{ $c['email'] }}</a>
                            </div>
                            @endif
                        </td>
                        <td>{{ $c['phone'] ?? '—' }}</td>
                        <td>
                            @if($c['connected'])
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Connected</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Lead</span>
                            @endif
                            @if($c['sourced'])
                                <span class="badge bg-info-subtle text-info border border-info-subtle">Sourced</span>
                            @endif
                        </td>
                        <td>
                            @forelse(array_slice($c['tags'], 0, 4) as $tag)
                                <span class="badge bg-light text-dark border">{{ $tag }}</span>
                            @empty
                                <span class="text-muted">—</span>
                            @endforelse
                            @if(count($c['tags']) > 4)
                                <span class="text-muted small">+{{ count($c['tags']) - 4 }}</span>
                            @endif
                        </td>
                        <td>{{ $c['created_at'] ? \Illuminate\Support\Carbon::parse($c['created_at'])->format('d M Y') : '—' }}</td>
                        <td class="pe-3 text-end">
                            @if($c['linkedin'])
                                <a href="{{ $c['linkedin'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" title="LinkedIn">
                                    <i class="bi bi-linkedin"></i>
                                </a>
                            @endif
                            @if($c['resume'])
                                <a href="{{ $c['resume'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Resume">
                                    <i class="bi bi-file-earmark-text"></i>
                                </a>
                            @endif
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
