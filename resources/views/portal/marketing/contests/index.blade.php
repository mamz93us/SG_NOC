@extends('layouts.marketing')
@section('title', 'World Cup Contests')

@section('content')
@php $flagBase = asset(trim((string) config('worldcup.flag_path', 'images/flags'), '/')); @endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-trophy-fill text-warning me-2"></i>World Cup Contests</h4>
    <a href="{{ route('portal.marketing.contests.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i>New contest
    </a>
</div>

@if($forms->isEmpty())
<div class="card shadow-sm"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-trophy display-5 d-block mb-2"></i>
    No contests yet. Create one — pick two teams and you'll get a shareable link for your email campaign.
</div></div>
@else
<div class="card shadow-sm"><div class="card-body p-0">
<table class="table table-hover align-middle mb-0">
    <thead class="table-light">
        <tr><th>Contest</th><th>Match</th><th class="text-center">Responses</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
        @foreach($forms as $form)
        @php $wc = $form->settings['worldcup'] ?? []; $home = $wc['home'] ?? null; $away = $wc['away'] ?? null; @endphp
        <tr>
            <td>
                <div class="fw-semibold">{{ $form->name }}</div>
                <div class="text-muted small">{{ $form->created_at?->format('d M Y') }}</div>
            </td>
            <td class="small">
                @if($home)<img src="{{ $flagBase }}/{{ $home['code'] }}.png" style="height:16px;" alt=""> {{ $home['name'] }}@endif
                <span class="text-muted mx-1">vs</span>
                @if($away)<img src="{{ $flagBase }}/{{ $away['code'] }}.png" style="height:16px;" alt=""> {{ $away['name'] }}@endif
            </td>
            <td class="text-center"><span class="badge bg-primary">{{ $form->submissions_count }}</span></td>
            <td>
                @if($form->isOpen())
                <span class="badge bg-success">Open</span>
                @else
                <span class="badge bg-secondary">Closed</span>
                @endif
            </td>
            <td class="text-end">
                <a href="{{ route('portal.marketing.contests.show', $form) }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-bar-chart me-1"></i>Results & link
                </a>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div></div>
@endif
@endsection
