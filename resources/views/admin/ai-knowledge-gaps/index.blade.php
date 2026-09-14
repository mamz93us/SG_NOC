@extends('layouts.admin')

@section('title', 'AI Knowledge Gaps')

@section('content')
@php
    $sourceBadges = [
        'no_results' => ['No results', 'text-bg-light border'],
        'not_answered' => ['Results did not answer', 'text-bg-warning'],
        'not_helpful' => ['Rated not helpful', 'text-bg-danger'],
    ];
    $resolutionBadges = [
        'answered' => ['Answered', 'text-bg-success'],
        'covered' => ['Covered by an article', 'text-bg-primary'],
        'dismissed' => ['Dismissed', 'text-bg-secondary'],
    ];
    $answered = session('answered');

    $tiles = [
        ['label' => 'Open questions', 'value' => number_format($groups->count()), 'note' => number_format($openCount).' '.Str::plural('wording', $openCount)],
        ['label' => 'Times asked', 'value' => number_format($asks), 'note' => 'across the open questions'],
        ['label' => 'Answered', 'value' => number_format($closedRecently['answered'] ?? 0), 'note' => 'written here, last 30 days'],
        ['label' => 'Covered', 'value' => number_format($closedRecently['covered'] ?? 0), 'note' => 'by an article published since, last 30 days'],
    ];
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-question-circle me-2 text-primary"></i>AI Knowledge Gaps</h4>
        <small class="text-muted">
            Questions the AI Assistant could not answer, grouped by what they ask. Answer one and the assistant knows it from then on, in English and Arabic.
            @if($lastChecked)
                Last compared with the knowledge base {{ \Illuminate\Support\Carbon::parse($lastChecked)->diffForHumans() }}.
            @endif
        </small>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success d-flex gap-2 align-items-start">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div>{{ session('success') }}</div>
    </div>
@endif

@if (session('error'))
    <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="bi bi-x-circle-fill fs-5"></i>
        <div>{{ session('error') }}</div>
    </div>
@endif

@if($answered)
    <div class="card border-success shadow-sm mb-4">
        <div class="card-body">
            <div class="fw-semibold mb-1">
                <i class="bi bi-check-circle-fill text-success me-1"></i>Published “{{ $answered['title'] }}”
                @can('manage-ai-assistant')
                    · <a href="{{ route('admin.ai-assistant.knowledge.edit', $answered['article_id']) }}" class="fw-normal">edit the article</a>
                @endcan
            </div>
            @unless($answered['indexed'])
                <div class="small text-danger mb-2">It is published, but indexing failed: the assistant cannot find it until Reindex All succeeds.</div>
            @endunless
            <div class="small text-muted mb-2">How closely the answer now matches each wording. The assistant uses anything from {{ number_format($floor, 2) }} up.</div>
            <ul class="list-unstyled small mb-0">
                @foreach($answered['scores'] as $row)
                    <li class="d-flex gap-2 align-items-baseline mb-1">
                        @if($row['score'] === null)
                            <i class="bi bi-dash-circle text-muted"></i><span class="text-muted">not measured</span>
                        @elseif($row['score'] >= $floor)
                            <i class="bi bi-check-circle-fill text-success" aria-label="found"></i><span style="font-variant-numeric: tabular-nums">{{ number_format($row['score'], 2) }}</span>
                        @else
                            <i class="bi bi-exclamation-triangle-fill text-warning" aria-label="below the floor"></i><span style="font-variant-numeric: tabular-nums">{{ number_format($row['score'], 2) }}</span>
                        @endif
                        <span>{{ $row['question'] }}</span>
                        @if($row['score'] !== null && $row['score'] < $floor)
                            <span class="text-muted">— below the floor; use this wording in the answer too</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

<div class="row g-3 mb-4">
    @foreach($tiles as $tile)
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="text-muted small">{{ $tile['label'] }}</div>
                    <div class="fs-3 fw-semibold">{{ $tile['value'] }}</div>
                    <div class="small text-muted">{{ $tile['note'] }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-inbox me-1 text-primary"></i>Open questions</div>
    <div class="list-group list-group-flush">
        @forelse($groups as $group)
            @php
                $lead = $group['lead'];
                $others = $group['gaps']->slice(1);
            @endphp
            <div class="list-group-item py-3">
                <div class="d-flex flex-wrap justify-content-between gap-3">
                    <div class="flex-grow-1" style="min-width:260px">
                        <div class="fw-semibold" @if($lead->locale === 'ar') dir="rtl" @endif>{{ $lead->query_sample }}</div>
                        <div class="small text-muted mt-1">
                            Asked {{ $group['hits'] }} {{ Str::plural('time', $group['hits']) }}
                            @if($group['last_seen'])
                                · last {{ $group['last_seen']->diffForHumans() }}
                            @endif
                            @foreach($group['sources'] as $source)
                                <span class="badge {{ $sourceBadges[$source][1] ?? 'text-bg-light border' }} fw-normal ms-1">{{ $sourceBadges[$source][0] ?? $source }}</span>
                            @endforeach
                        </div>

                        @if($others->isNotEmpty())
                            <details class="small mt-2">
                                <summary class="text-muted">{{ $others->count() }} other {{ Str::plural('wording', $others->count()) }}</summary>
                                <ul class="mb-0 mt-1 ps-3">
                                    @foreach($others as $gap)
                                        <li @if($gap->locale === 'ar') dir="rtl" @endif>{{ $gap->query_sample }} <span class="text-muted">({{ $gap->hit_count }})</span></li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        @if($group['suggestion'])
                            <div class="small mt-2 d-flex flex-wrap align-items-center gap-2">
                                <span class="text-muted">Possibly answered already by</span>
                                <span class="fw-semibold">“{{ $group['suggestion']->bestArticle->title }}”</span>
                                <span class="text-muted">({{ number_format($group['suggestion']->best_score, 2) }})</span>
                                <form method="POST" action="{{ route('admin.ai-assistant.knowledge-gaps.confirm', $group['suggestion']) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-primary py-0">It does — close</button>
                                </form>
                            </div>
                        @endif
                    </div>

                    <div class="d-flex align-items-start gap-2">
                        <a href="{{ route('admin.ai-assistant.knowledge-gaps.answer-form', $lead) }}" class="btn btn-sm btn-primary">
                            <i class="bi bi-pencil-square me-1"></i>Answer
                        </a>
                        <form method="POST" action="{{ route('admin.ai-assistant.knowledge-gaps.dismiss') }}"
                              onsubmit="return confirm('Dismiss this question? It stays dismissed if asked again.');">
                            @csrf
                            @foreach($group['gaps'] as $gap)
                                <input type="hidden" name="gap_ids[]" value="{{ $gap->id }}">
                            @endforeach
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Not something the company documentation should answer">Dismiss</button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="list-group-item text-center text-muted py-5">
                <i class="bi bi-check2-circle fs-3 d-block mb-2"></i>No open questions. Everything employees asked has an answer.
            </div>
        @endforelse
    </div>
</div>

@if($resolved->isNotEmpty())
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-check2-all me-1 text-success"></i>Recently closed</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Question</th>
                        <th>Closed as</th>
                        <th>Article</th>
                        <th>By</th>
                        <th>When</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($resolved as $gap)
                        <tr>
                            <td class="small" @if($gap->locale === 'ar') dir="rtl" @endif>{{ $gap->query_sample }}</td>
                            <td><span class="badge {{ $resolutionBadges[$gap->resolution][1] ?? 'text-bg-secondary' }} fw-normal">{{ $resolutionBadges[$gap->resolution][0] ?? $gap->resolution }}</span></td>
                            <td class="small">{{ $gap->article?->title ?? '—' }}</td>
                            <td class="small text-muted">{{ $gap->resolver?->name ?? ($gap->resolution === 'covered' ? 'Automatically' : '—') }}</td>
                            <td class="small text-muted text-nowrap">{{ $gap->resolved_at?->diffForHumans() }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('admin.ai-assistant.knowledge-gaps.reopen', $gap) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Reopen</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
