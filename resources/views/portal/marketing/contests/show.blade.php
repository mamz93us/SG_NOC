@extends('layouts.marketing')
@section('title', $form->name)

@section('content')
@php
    $flagBase = asset(trim((string) config('worldcup.flag_path', 'images/flags'), '/'));
    $wc   = $form->settings['worldcup'] ?? [];
    $home = $wc['home'] ?? null;
    $away = $wc['away'] ?? null;
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-trophy-fill text-warning me-2"></i>{{ $form->name }}</h4>
    <a href="{{ route('portal.marketing.contests.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>All contests
    </a>
</div>

<div class="row g-3">
    {{-- Share + controls --}}
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h6 class="fw-semibold mb-2">
                    @if($home)<img src="{{ $flagBase }}/{{ $home['code'] }}.png" style="height:18px;" alt=""> {{ $home['name'] }}@endif
                    <span class="text-muted mx-1">vs</span>
                    @if($away)<img src="{{ $flagBase }}/{{ $away['code'] }}.png" style="height:18px;" alt=""> {{ $away['name'] }}@endif
                </h6>
                @if(!empty($wc['kickoff']))<div class="text-muted small mb-3"><i class="bi bi-clock me-1"></i>{{ $wc['kickoff'] }}</div>@endif

                <label class="form-label fw-semibold small">Link to share in your email campaign</label>
                <div class="input-group input-group-sm mb-2">
                    <input type="text" class="form-control font-monospace" readonly value="{{ $url }}" id="contestUrl">
                    <button class="btn btn-outline-secondary" type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('contestUrl').value)">
                        <i class="bi bi-clipboard"></i>
                    </button>
                    <a href="{{ $url }}" target="_blank" class="btn btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i></a>
                </div>
                <div class="small mb-3">
                    Status:
                    @if($form->isOpen())<span class="badge bg-success">Open</span>
                    @else<span class="badge bg-secondary">Closed</span>@endif
                    @if($form->expires_at)<span class="text-muted ms-1">· closes {{ $form->expires_at->format('d M Y') }}</span>@endif
                </div>

                <div class="d-flex gap-2">
                    <a href="{{ route('portal.marketing.contests.export', $form) }}" class="btn btn-sm btn-success">
                        <i class="bi bi-download me-1"></i>Export CSV
                    </a>
                    <form method="POST" action="{{ route('portal.marketing.contests.toggle', $form) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-{{ $form->is_active ? 'danger' : 'success' }}">
                            <i class="bi bi-{{ $form->is_active ? 'lock' : 'unlock' }} me-1"></i>{{ $form->is_active ? 'Close' : 'Re-open' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="alert alert-light border small">
            <i class="bi bi-info-circle me-1"></i>To pick winners, export the CSV and sort by <strong>Submitted At</strong>
            (for the first entries) or filter by the exact score once the real result is known.
        </div>
    </div>

    {{-- Responses --}}
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header py-2 fw-semibold small">
                <i class="bi bi-list-check me-1"></i>Responses ({{ $submissions->total() }})
            </div>
            <div class="card-body p-0">
                @if($submissions->isEmpty())
                <p class="text-muted text-center py-4 mb-0">No guesses yet.</p>
                @else
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Employee</th><th class="text-center">{{ $home['name'] ?? 'Home' }}</th>
                            <th class="text-center">{{ $away['name'] ?? 'Away' }}</th><th>Submitted</th></tr>
                    </thead>
                    <tbody>
                        @foreach($submissions as $s)
                        <tr>
                            <td>{{ $s->submittedBy?->name ?? ($s->submitter_email ?? 'Anonymous') }}</td>
                            <td class="text-center fw-bold">{{ $s->data['home_score'] ?? '—' }}</td>
                            <td class="text-center fw-bold">{{ $s->data['away_score'] ?? '—' }}</td>
                            <td class="small text-muted">{{ $s->created_at?->format('d M H:i') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
            @if($submissions->hasPages())
            <div class="card-footer">{{ $submissions->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
