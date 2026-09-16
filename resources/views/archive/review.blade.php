@extends('layouts.archive')

@section('title', 'Review AI proposals')

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ route('archive.index') }}" class="arc-muted text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Archives
        </a>
        <span class="arc-muted">/</span>
        <span class="fw-semibold">Review AI proposals</span>

        <span class="ms-auto arc-muted small">
            {{ number_format($proposals->total()) }} waiting
        </span>
    </div>

    {{-- What this page is, in one line, because approving a wrong value here
         writes it onto a financial document. --}}
    <div class="arc-card p-3 mb-3">
        <p class="small mb-0">
            AI read these scans and proposed values for index fields that were left empty.
            <span class="fw-semibold">Nothing here has been saved yet.</span>
            Each proposal names the page it was read off — open the document and check it
            rather than taking the figure on trust.
        </p>
    </div>

    <div class="row g-3">
        <div class="col-lg-3">
            {{-- ── Which archive ───────────────────────────────────── --}}
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-3">Archives</h2>

                <div class="list-group list-group-flush small">
                    <a href="{{ route('archive.review', ['order' => $order === 'desc' ? 'confident' : null]) }}"
                       class="list-group-item list-group-item-action d-flex justify-content-between px-0
                              @if (! $archive) fw-semibold @endif">
                        <span>All archives</span>
                        <span class="arc-muted">{{ array_sum($counts) }}</span>
                    </a>

                    @foreach ($archives as $option)
                        @continue (($counts[$option->id] ?? 0) === 0)
                        <a href="{{ route('archive.review', ['archive' => $option->slug, 'order' => $order === 'desc' ? 'confident' : null]) }}"
                           class="list-group-item list-group-item-action d-flex justify-content-between px-0
                                  @if ($archive?->id === $option->id) fw-semibold @endif">
                            <span class="text-break">{{ $option->displayName() }}</span>
                            <span class="arc-muted">{{ number_format($counts[$option->id]) }}</span>
                        </a>
                    @endforeach
                </div>

                @if ($archives->isEmpty())
                    <p class="arc-muted small mb-0">
                        You do not have edit rights on any archive, so there is nothing to review.
                    </p>
                @endif
            </div>

            {{-- ── Sort ────────────────────────────────────────────── --}}
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-2">Order</h2>
                <div class="btn-group btn-group-sm w-100">
                    <a class="btn {{ $order === 'asc' ? 'btn-brand' : 'btn-outline-secondary' }}"
                       href="{{ route('archive.review', ['archive' => $archive?->slug]) }}">Least sure</a>
                    <a class="btn {{ $order === 'desc' ? 'btn-brand' : 'btn-outline-secondary' }}"
                       href="{{ route('archive.review', ['archive' => $archive?->slug, 'order' => 'confident']) }}">Most sure</a>
                </div>
                <p class="arc-muted small mb-0 mt-2">
                    Least sure first by default — that is where looking actually changes the answer.
                </p>
            </div>

            {{-- ── Bulk ────────────────────────────────────────────── --}}
            @if ($archive && ($counts[$archive->id] ?? 0) > 0)
                <div class="arc-card p-3">
                    <h2 class="h6 mb-2">Approve the confident ones</h2>

                    <form method="POST" action="{{ route('archive.review.bulk') }}">
                        @csrf
                        <input type="hidden" name="archive" value="{{ $archive->id }}">

                        <label class="form-label small arc-muted mb-1">Confidence at least</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="number" class="form-control" name="confidence"
                                   min="{{ $minBulkConfidence }}" max="100" value="95">
                            <span class="input-group-text">%</span>
                        </div>

                        <button class="btn btn-sm btn-outline-secondary w-100"
                                onclick="return confirm('Approve every proposal at or above that confidence in {{ $archive->displayName() }}? The values are written to the documents.')">
                            Approve in {{ $archive->displayName() }}
                        </button>
                    </form>

                    <p class="arc-muted small mb-0 mt-2">
                        {{ $minBulkConfidence }}% is the floor, whatever is typed — approving
                        everything is not reviewing anything. Up to 500 at a time.
                    </p>
                </div>
            @endif
        </div>

        {{-- ── The queue ───────────────────────────────────────────── --}}
        <div class="col-lg-9">
            <div class="arc-card p-3">
                @if ($proposals->isEmpty())
                    <div class="arc-empty">
                        Nothing waiting.
                        <div class="small mt-1">
                            Proposals appear here after a “propose values” batch runs on the AI page.
                        </div>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table arc-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Field</th>
                                    <th style="width:28%">AI proposes</th>
                                    <th>Sure</th>
                                    <th class="text-end">Decision</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($proposals as $proposal)
                                    <tr>
                                        <td>
                                            <a class="fw-semibold text-decoration-none"
                                               href="{{ route('archive.document', $proposal->archive_document_id) }}"
                                               target="_blank" rel="noopener">
                                                {{ $proposal->document?->title() ?? '#'.$proposal->archive_document_id }}
                                                <i class="bi bi-box-arrow-up-right small"></i>
                                            </a>
                                            <div class="arc-muted small">
                                                {{ $proposal->document?->archive?->displayName() }}
                                                @if ($proposal->document?->captured_at)
                                                    · {{ $proposal->document->captured_at->format('Y-m-d') }}
                                                @endif
                                            </div>
                                        </td>
                                        <td class="small">{{ $proposal->field?->label() }}</td>

                                        <td>
                                            {{-- One form per row. The value is editable in place:
                                                 correcting is the common case, and a reviewer who
                                                 has to go elsewhere to fix a digit will approve
                                                 the wrong one instead. --}}
                                            <form method="POST" id="p{{ $proposal->id }}"
                                                  action="{{ route('archive.review.decide', $proposal) }}" class="m-0">
                                                @csrf
                                                <input class="form-control form-control-sm" name="value"
                                                       value="{{ $proposal->value }}" maxlength="250">
                                            </form>
                                        </td>

                                        <td>
                                            @php($sure = (int) $proposal->confidence)
                                            <span class="badge"
                                                  style="background:{{ $sure >= 90 ? 'var(--green)' : ($sure >= 70 ? 'var(--amber)' : 'var(--gray-500)') }}">
                                                {{ $sure }}%
                                            </span>
                                            <div class="arc-muted small">
                                                @if ($proposal->evidence_page)
                                                    read on page {{ $proposal->evidence_page }}
                                                @else
                                                    page not recorded
                                                @endif
                                            </div>
                                        </td>

                                        <td class="text-end text-nowrap">
                                            <button class="btn btn-sm btn-brand" form="p{{ $proposal->id }}"
                                                    name="decision" value="approve">Approve</button>
                                            <button class="btn btn-sm btn-outline-secondary" form="p{{ $proposal->id }}"
                                                    name="decision" value="edit">Save as edited</button>
                                            <button class="btn btn-sm btn-outline-secondary" form="p{{ $proposal->id }}"
                                                    name="decision" value="reject">Reject</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">{{ $proposals->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection
