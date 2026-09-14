@extends('layouts.admin')

@section('title', 'AI Knowledge Statistics')

@section('content')
@php
    $totals = $stats['totals'];
    $imports = $stats['imports'];
    $settings = $stats['settings'];
    $languages = $stats['languages'];
    $languageNames = ['en' => 'English', 'ar' => 'Arabic', 'other' => 'Untagged'];

    $bytes = function (?int $value): string {
        if ($value === null) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $value;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? number_format($size) : number_format($size, $size < 10 ? 1 : 0)).' '.$units[$unit];
    };

    $compact = fn (int $n): string => match (true) {
        $n >= 1_000_000 => number_format($n / 1_000_000, 1).'M',
        $n >= 10_000 => number_format($n / 1_000).'K',
        $n >= 1_000 => number_format($n / 1_000, 1).'K',
        default => number_format($n),
    };

    $storageBytes = $stats['storage'] === null
        ? null
        : array_sum(array_map(fn (array $table) => $table['data_bytes'] + $table['index_bytes'], $stats['storage']));

    $bandPeak = max(1, ...array_column($stats['sizes'], 'total'));
    $showUntagged = array_sum(array_column($stats['sizes'], 'other')) > 0;

    $tiles = [
        [
            'label' => 'Articles',
            'value' => number_format($totals['articles']),
            'note' => $totals['published'].' published · '.$totals['drafts'].' '.Str::plural('draft', $totals['drafts']).' · '.$totals['imported'].' from PDFs'.($totals['websites'] ? ' · '.$totals['websites'].' from websites' : ''),
        ],
        [
            'label' => 'Chunks',
            'value' => number_format($totals['chunks']),
            'note' => collect($languages)->map(fn ($l, $code) => ($languageNames[$code] ?? $code).' '.number_format($l['chunks']))->implode(' · ') ?: 'Nothing indexed yet',
        ],
        [
            'label' => 'Indexed text',
            'value' => $compact($totals['characters']),
            'note' => 'characters · '.number_format($totals['average_chunk']).' a chunk on average',
        ],
        [
            'label' => 'Embeddings',
            'value' => $bytes($totals['embedding_bytes']),
            'note' => number_format($totals['chunks']).' '.Str::plural('vector', $totals['chunks']).' · '.($settings['embedding_deployment'] ?: 'no deployment set'),
        ],
        [
            'label' => 'Database storage',
            'value' => $bytes($storageBytes),
            'note' => $storageBytes === null ? 'Only reported on MySQL' : 'Knowledge tables, MySQL estimate',
        ],
        [
            'label' => 'Original PDFs',
            'value' => $bytes($imports['bytes']),
            'note' => number_format($imports['files']).' '.Str::plural('file', $imports['files']).' · '.number_format($imports['pages']).' pages read',
        ],
    ];
@endphp

<style>
    .kb-stats .num { font-variant-numeric: tabular-nums; text-align: end; white-space: nowrap; }
    .kb-bar { height: 14px; background: var(--bs-primary); border-radius: 0 4px 4px 0; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-bar-chart me-2 text-primary"></i>AI Knowledge Statistics</h4>
        <small class="text-muted">How much the AI Assistant can search, how it is cut into chunks, and what it takes to store.</small>
    </div>
    <a href="{{ route('admin.ai-assistant.knowledge.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Knowledge
    </a>
</div>

@if($totals['not_indexed'] > 0)
    <div class="alert alert-warning d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div>
            {{ $totals['not_indexed'] }} published {{ Str::plural('article', $totals['not_indexed']) }}
            {{ $totals['not_indexed'] === 1 ? 'has' : 'have' }} no chunks, so the assistant cannot search
            {{ $totals['not_indexed'] === 1 ? 'it' : 'them' }}. Use <strong>Reindex All</strong> on the Knowledge page.
        </div>
    </div>
@endif

@if($totals['unembedded'] > 0)
    <div class="alert alert-warning d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div>{{ number_format($totals['unembedded']) }} {{ Str::plural('chunk', $totals['unembedded']) }} have no embedding and are skipped by search.</div>
    </div>
@endif

<div class="row g-3 mb-4">
    @foreach($tiles as $tile)
        <div class="col-6 col-lg-4 col-xl-2">
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

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white">
                <div class="fw-semibold"><i class="bi bi-distribute-vertical me-1 text-primary"></i>Chunk sizes</div>
                <div class="small text-muted">
                    Characters per chunk. Sections longer than {{ number_format($settings['chunk_max_characters']) }} characters are cut at paragraphs, so the last band should stay empty.
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 kb-stats">
                    <thead class="table-light">
                        <tr>
                            <th>Characters</th>
                            <th class="num">English</th>
                            <th class="num">Arabic</th>
                            @if($showUntagged)
                                <th class="num">Untagged</th>
                            @endif
                            <th style="width:40%">All chunks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stats['sizes'] as $band)
                            <tr>
                                <td class="text-nowrap">{{ $band['label'] }}</td>
                                <td class="num">{{ number_format($band['en']) }}</td>
                                <td class="num">{{ number_format($band['ar']) }}</td>
                                @if($showUntagged)
                                    <td class="num">{{ number_format($band['other']) }}</td>
                                @endif
                                <td>
                                    <div class="d-flex align-items-center gap-2"
                                         title="{{ $band['label'] }} characters: {{ number_format($band['total']) }} {{ Str::plural('chunk', $band['total']) }}">
                                        @if($band['total'] > 0)
                                            <div class="kb-bar" style="width: calc({{ round(100 * $band['total'] / $bandPeak, 1) }}% - 3.5rem)"></div>
                                        @endif
                                        <span class="small">{{ number_format($band['total']) }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white">
                <div class="fw-semibold"><i class="bi bi-translate me-1 text-primary"></i>Languages</div>
                <div class="small text-muted">An imported Arabic PDF is indexed twice: its English translation and its original text.</div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 kb-stats">
                    <thead class="table-light">
                        <tr>
                            <th>Language</th>
                            <th class="num">Chunks</th>
                            <th class="num">Characters</th>
                            <th class="num">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($languages as $code => $language)
                            <tr>
                                <td>{{ $languageNames[$code] ?? $code }}</td>
                                <td class="num">{{ number_format($language['chunks']) }}</td>
                                <td class="num">{{ number_format($language['characters']) }}</td>
                                <td class="num">{{ $totals['chunks'] ? round(100 * $language['chunks'] / $totals['chunks']) : 0 }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">Nothing indexed yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white">
        <div class="fw-semibold"><i class="bi bi-journal-text me-1 text-primary"></i>By source</div>
        <div class="small text-muted">
            Every article, and any Employee Documents PDF the assistant has indexed. <em>With page</em> is the share of an imported PDF's chunks the assistant can cite by page.
        </div>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0 kb-stats">
            <thead class="table-light">
                <tr>
                    <th>Source</th>
                    <th>Status</th>
                    <th class="num">Chunks</th>
                    <th class="num">English</th>
                    <th class="num">Arabic</th>
                    <th class="num">Characters</th>
                    <th class="num">Average</th>
                    <th class="num">Largest</th>
                    <th class="num">Embeddings</th>
                    <th class="num">With page</th>
                    <th>Indexed</th>
                </tr>
            </thead>
            <tbody>
                @forelse($stats['sources'] as $source)
                    <tr>
                        <td style="min-width:220px">
                            @if($source['article_id'])
                                <a href="{{ route('admin.ai-assistant.knowledge.edit', $source['article_id']) }}" class="fw-semibold">{{ $source['title'] }}</a>
                            @else
                                <span class="fw-semibold">{{ $source['title'] }}</span>
                            @endif
                            <div class="small text-muted">
                                @if($source['kind'] === 'pdf')
                                    <i class="bi bi-file-earmark-pdf text-danger"></i> {{ $source['file_name'] }}
                                    @if($source['page_count'])
                                        · {{ $source['page_count'] }} pages
                                    @endif
                                @elseif($source['kind'] === 'library')
                                    <i class="bi bi-folder2-open"></i> Employee Documents · {{ $source['file_name'] }}
                                @elseif($source['kind'] === 'website')
                                    <i class="bi bi-globe2"></i> <span class="text-break">{{ $source['url'] }}</span>
                                @else
                                    Written here
                                @endif
                                @if($source['category'])
                                    · {{ $source['category'] }}
                                @endif
                            </div>
                        </td>
                        <td>
                            @if($source['published'] && $source['chunks'] === 0)
                                <span class="badge bg-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Not indexed</span>
                            @elseif($source['published'])
                                <span class="badge bg-success">Live</span>
                            @else
                                <span class="badge bg-secondary">Draft</span>
                            @endif
                        </td>
                        <td class="num fw-semibold">{{ number_format($source['chunks']) }}</td>
                        <td class="num">{{ number_format($source['chunks_en']) }}</td>
                        <td class="num">{{ number_format($source['chunks_ar']) }}</td>
                        <td class="num">{{ number_format($source['characters']) }}</td>
                        <td class="num">{{ $source['chunks'] ? number_format($source['average_chunk']) : '—' }}</td>
                        <td class="num">{{ $source['chunks'] ? number_format($source['largest_chunk']) : '—' }}</td>
                        <td class="num">{{ $source['chunks'] ? $bytes($source['embedding_bytes']) : '—' }}</td>
                        <td class="num">
                            {{ $source['kind'] === 'pdf' && $source['chunks'] > 0 ? round(100 * $source['with_page'] / $source['chunks']).'%' : '—' }}
                        </td>
                        <td class="small text-muted text-nowrap">
                            {{ $source['last_indexed'] ? \Illuminate\Support\Carbon::parse($source['last_indexed'])->diffForHumans() : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center text-muted py-5">No articles yet.</td></tr>
                @endforelse
            </tbody>
            @if($stats['sources']->isNotEmpty())
                <tfoot class="table-light">
                    <tr class="fw-semibold">
                        <td>Total</td>
                        <td></td>
                        <td class="num">{{ number_format($totals['chunks']) }}</td>
                        <td class="num">{{ number_format($languages['en']['chunks'] ?? 0) }}</td>
                        <td class="num">{{ number_format($languages['ar']['chunks'] ?? 0) }}</td>
                        <td class="num">{{ number_format($totals['characters']) }}</td>
                        <td class="num">{{ number_format($totals['average_chunk']) }}</td>
                        <td class="num">{{ number_format($totals['largest_chunk']) }}</td>
                        <td class="num">{{ $bytes($totals['embedding_bytes']) }}</td>
                        <td></td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>PDF imports</div>
            <div class="card-body">
                <dl class="row mb-0 small kb-stats">
                    <dt class="col-7 fw-normal text-muted">Files</dt>
                    <dd class="col-5 num">{{ number_format($imports['files']) }}</dd>
                    <dt class="col-7 fw-normal text-muted">Pages read</dt>
                    <dd class="col-5 num">{{ number_format($imports['pages']) }}</dd>
                    <dt class="col-7 fw-normal text-muted">Still being read</dt>
                    <dd class="col-5 num">{{ number_format($imports['active']) }}</dd>
                    <dt class="col-7 fw-normal text-muted">Failed</dt>
                    <dd class="col-5 num">{{ number_format($imports['failed']) }}</dd>
                    <dt class="col-7 fw-normal text-muted">Tokens sent to Azure</dt>
                    <dd class="col-5 num">{{ number_format($imports['prompt_tokens']) }}</dd>
                    <dt class="col-7 fw-normal text-muted">Tokens returned</dt>
                    <dd class="col-5 num mb-0">{{ number_format($imports['completion_tokens']) }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-database me-1 text-primary"></i>Storage</div>
            @if($stats['storage'] === null)
                <div class="card-body small text-muted">Table sizes are only reported on MySQL.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 kb-stats">
                        <thead class="table-light">
                            <tr>
                                <th>Table</th>
                                <th class="num">Rows</th>
                                <th class="num">Data</th>
                                <th class="num">Indexes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($stats['storage'] as $table)
                                <tr>
                                    <td class="small"><code>{{ $table['table'] }}</code></td>
                                    <td class="num">{{ number_format($table['rows']) }}</td>
                                    <td class="num">{{ $bytes($table['data_bytes']) }}</td>
                                    <td class="num">{{ $bytes($table['index_bytes']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white small text-muted">
                    {{ $bytes($storageBytes) }} in all. MySQL's own estimate: it lags until InnoDB refreshes its statistics.
                </div>
            @endif
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-sliders me-1 text-primary"></i>Search settings</div>
            <div class="card-body">
                <dl class="row mb-0 small kb-stats">
                    <dt class="col-7 fw-normal text-muted">Relevance floor</dt>
                    <dd class="col-5 num">{{ number_format((float) $settings['relevance_floor'], 2) }}</dd>
                    <dt class="col-7 fw-normal text-muted">Embedding deployment</dt>
                    <dd class="col-5 text-end text-break">{{ $settings['embedding_deployment'] ?: '—' }}</dd>
                    <dt class="col-7 fw-normal text-muted">Longest chunk</dt>
                    <dd class="col-5 num">{{ number_format($settings['chunk_max_characters']) }} chars</dd>
                    <dt class="col-7 fw-normal text-muted">Per embedding request</dt>
                    <dd class="col-5 num">{{ number_format($settings['batch_characters']) }} chars</dd>
                    <dt class="col-7 fw-normal text-muted">Open knowledge gaps</dt>
                    <dd class="col-5 num mb-0"><a href="{{ route('admin.ai-assistant.usage') }}">{{ number_format($totals['open_gaps']) }}</a></dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
