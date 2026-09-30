@extends('layouts.archive')

@section('title', $document->title())

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('archive.index') }}" class="arc-muted text-decoration-none small">Archives</a>
        <span class="arc-muted">/</span>
        <a href="{{ route('archive.show', $archive->slug) }}" class="arc-muted text-decoration-none small">{{ $archive->displayName() }}</a>
        <span class="arc-muted">/</span>
        <span class="fw-semibold">{{ $document->title() }}</span>
        <span class="small ms-auto {{ $reading->cssClass() }}" title="{{ $reading->detail() }}">
            <i class="bi {{ $reading->icon() }}"></i> {{ $reading->label() }}
        </span>
    </div>

    @if ($document->needs_review)
        {{-- The sync refused to overwrite a value somebody edited here. Shown to
             readers, not just administrators: the person looking at the invoice
             is the one who can tell which number is right. --}}
        <div class="alert alert-warning py-2">
            <i class="bi bi-exclamation-triangle"></i> {{ $document->review_note }}
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-4 col-xl-3">
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-3">Details</h2>
                <dl class="mb-0">
                    @foreach ($archive->fields as $field)
                        @php($value = $document->values->firstWhere('archive_field_id', $field->id))
                        <dt class="small arc-muted fw-normal">{{ $field->label() }}</dt>
                        @php($ai = $readings->get($field->id))
                        <dd class="mb-2">
                            {{ $value?->value_text ?: '—' }}
                            @if ($value && $value->source === \App\Models\Archive\ArchiveDocumentValue::SOURCE_AI_APPROVED)
                                <span class="badge badge-soft ms-1" title="Read by AI and approved by a person">from AI · approved</span>
                            @elseif ($value && ! $value->isFromArcMate())
                                <span class="badge badge-soft ms-1">edited here</span>
                            @endif

                            {{-- What AI read for this field, beside the recorded value and
                                 never in its place: a pending reading is a machine's guess
                                 until somebody approves it in the review queue. --}}
                            @if ($ai && $ai->status === \App\Models\Archive\ArchiveAiProposal::STATUS_PENDING && filled($ai->value))
                                <div class="small mt-1" style="color:var(--amber)"
                                     title="Read off the paper by AI. Nobody has checked it yet.">
                                    <i class="bi bi-stars"></i> AI read: <span class="fw-semibold" dir="auto">{{ $ai->value }}</span>
                                    <span class="arc-muted">
                                        @if ($ai->evidence_page) · page {{ $ai->evidence_page }} @endif
                                        @if ($ai->confidence !== null) · {{ $ai->confidence }}% sure @endif
                                        · not reviewed
                                    </span>
                                </div>
                            @elseif ($ai && $ai->status === \App\Models\Archive\ArchiveAiProposal::STATUS_NOT_FOUND)
                                <div class="small arc-muted mt-1">
                                    <i class="bi bi-stars"></i> AI looked for it and it is not on the paper
                                </div>
                            @elseif ($ai && $ai->status === \App\Models\Archive\ArchiveAiProposal::STATUS_REJECTED)
                                <div class="small arc-muted mt-1" title="A reviewer rejected what AI read here">
                                    <i class="bi bi-stars"></i> AI read {{ $ai->value }} · rejected
                                </div>
                            @endif
                        </dd>
                    @endforeach

                    <dt class="small arc-muted fw-normal">Scanned</dt>
                    <dd class="mb-2">{{ $document->captured_at?->format('Y-m-d H:i') ?? '—' }}</dd>

                    @if ($document->created_by_name)
                        <dt class="small arc-muted fw-normal">Filed by</dt>
                        <dd class="mb-0">{{ $document->created_by_name }}</dd>
                    @endif
                </dl>
            </div>

            @if ($canAsk)
                {{-- Asking about this document. Answered only from its own pages,
                     and every answer cites the page it came from — the text is a
                     machine reading of a scan, so "(page 2)" is what lets somebody
                     check it against the image on the right.

                     Unread pages are read on demand, which costs money, so the
                     panel says so rather than presenting this as free. --}}
                <div class="arc-card p-3 mb-3">
                    <h2 class="h6 mb-2">Ask about this document</h2>

                    <form id="askForm" class="mb-2">
                        <textarea class="form-control form-control-sm mb-2" id="askQuestion" rows="2"
                                  maxlength="1000" placeholder="What is the total? Who signed it?"></textarea>
                        <button class="btn btn-brand btn-sm w-100" type="submit" id="askSend">Ask</button>
                    </form>

                    <div id="askAnswer" class="small d-none"></div>

                    <p class="arc-muted small mb-0" id="askNote">
                        Answered only from this document's pages. Pages that have never been
                        read are read now, which costs against the archive's AI budget.
                    </p>
                </div>

                {{-- Inline, not @push('scripts'): a partial's push can land after the
                     code that needs it, and this belongs to this panel alone. --}}
                <script>
                (function () {
                    const form   = document.getElementById('askForm');
                    const box    = document.getElementById('askQuestion');
                    const send   = document.getElementById('askSend');
                    const answer = document.getElementById('askAnswer');
                    const note   = document.getElementById('askNote');

                    form.addEventListener('submit', function (event) {
                        event.preventDefault();

                        const question = box.value.trim();
                        if (question === '') { return; }

                        send.disabled = true;
                        send.textContent = 'Reading…';
                        answer.classList.remove('d-none');
                        answer.textContent = 'Reading the pages…';

                        fetch(@json(route('archive.ask.document', $document->id)), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ question: question }),
                        })
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            // textContent throughout: the answer quotes a scanned
                            // document, and that text is never markup.
                            answer.textContent = data.answer || data.error || 'No answer came back.';

                            if (data.pages && data.pages.length) {
                                const cited = document.createElement('div');
                                cited.className = 'arc-muted small mt-2';
                                cited.textContent = 'From page(s) ' + data.pages.join(', ') + '.';
                                answer.appendChild(cited);
                            }

                            if (data.pages_read_now > 0) {
                                note.textContent = data.pages_read_now
                                    + ' page(s) were read for that. Asking again about them costs nothing.';
                            }
                        })
                        .catch(function () {
                            answer.textContent = 'The AI service could not answer that just now.';
                        })
                        .finally(function () {
                            send.disabled = false;
                            send.textContent = 'Ask';
                        });
                    });
                })();
                </script>
            @endif

            <div class="arc-card p-3">
                <h2 class="h6 mb-3">Files <span class="arc-muted fw-normal">({{ $files->count() }})</span></h2>

                @forelse ($files as $file)
                    @php($storage = $file->storage())
                    <div class="d-flex align-items-center justify-content-between gap-2 py-2 {{ ! $loop->last ? 'border-bottom' : '' }}">
                        <div class="text-truncate">
                            <i class="bi {{ $file->isEmail() ? 'bi-envelope' : ($file->isPdf() ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image') }}"></i>
                            <a href="{{ route('archive.document', ['id' => $document->id, 'file' => $file->id]) }}"
                               class="text-decoration-none small">{{ $file->downloadName() }}</a>
                            {{-- Where the bytes are. It matters to a reader and not only
                                 to an admin: a file still on ArcMate is served through the
                                 cifs mount, so it depends on that VM being up. --}}
                            <div class="small {{ $storage['class'] }}" title="{{ $storage['detail'] }}">
                                <i class="bi {{ $storage['icon'] }}"></i> {{ $storage['label'] }}
                            </div>
                            @php($fileState = $fileReading[$file->id] ?? null)
                            @if ($fileState && $fileState->state !== \App\Services\Archive\Ai\ReadingState::NONE)
                                <div class="small {{ $fileState->cssClass() }}" title="{{ $fileState->detail() }}">
                                    <i class="bi {{ $fileState->icon() }}"></i> {{ $fileState->label() }}
                                </div>
                            @endif
                        </div>
                        <a href="{{ route('archive.document.download', [$document->id, $file->id]) }}"
                           class="btn btn-sm btn-outline-secondary" title="Download">
                            <i class="bi bi-download"></i>
                        </a>
                    </div>
                @empty
                    @if ($backfilling)
                        {{-- Still mirroring: documents come over before their files, so
                             this is a queue position, not a missing scan. --}}
                        <p class="arc-muted small mb-0">
                            <i class="bi bi-hourglass-split"></i>
                            Still being copied from ArcMate. Documents arrive before their
                            scans do, so this one has not reached the front of the queue yet.
                        </p>
                    @else
                        <p class="arc-muted small mb-0">This document has no files.</p>
                    @endif
                @endforelse
            </div>
        </div>

        <div class="col-lg-8 col-xl-9">
            @if (! $primary)
                @if ($backfilling)
                    <div class="arc-card arc-empty">
                        <i class="bi bi-hourglass-split" style="font-size:1.5rem"></i>
                        <p class="mt-2 mb-1">The scan is still being copied from ArcMate.</p>
                        <p class="small mb-0">
                            Every document is copied first and its files follow, so this page
                            fills in on its own. Nothing is missing.
                        </p>
                    </div>
                @else
                    <div class="arc-card arc-empty">Nothing to display.</div>
                @endif
            @elseif ($missing)
                {{-- The index row is real but the bytes are not where ArcMate said.
                     Reported rather than hidden: a file that has gone missing on the
                     share is worth knowing about before the old server is retired. --}}
                <div class="arc-card arc-empty">
                    <i class="bi bi-file-earmark-x" style="font-size:1.5rem"></i>
                    <p class="mt-2 mb-1">This file is not where ArcMate recorded it.</p>
                    <p class="small mb-0 text-break">{{ $primary->path }}</p>
                </div>
            @elseif ($converterMissing)
                <div class="arc-card arc-empty">
                    <i class="bi bi-file-earmark-image" style="font-size:1.5rem"></i>
                    <p class="mt-2 mb-1">This is a multi-page TIFF, which browsers cannot show.</p>
                    <p class="small mb-2">The converter (libtiff-tools) is not installed on this server.</p>
                    <a class="btn btn-sm btn-brand" href="{{ route('archive.document.download', [$document->id, $primary->id]) }}">
                        <i class="bi bi-download"></i> Download it
                    </a>
                </div>
            @elseif ($primary->isViewable())
                @if ($primary->isImage())
                    <div class="arc-card p-3 text-center">
                        <img src="{{ route('archive.document.stream', [$document->id, $primary->id]) }}"
                             alt="{{ $primary->downloadName() }}" class="img-fluid">
                    </div>
                @elseif ($primary->isEmail())
                    {{-- Outlook .msg: no reader installed, so the honest thing is to
                         offer it rather than render an empty frame. --}}
                    <div class="arc-card arc-empty">
                        <i class="bi bi-envelope" style="font-size:1.5rem"></i>
                        <p class="mt-2 mb-2">This is an attached Outlook e-mail.</p>
                        <a class="btn btn-sm btn-brand" href="{{ route('archive.document.download', [$document->id, $primary->id]) }}">
                            <i class="bi bi-download"></i> Open in Outlook
                        </a>
                    </div>
                @else
                    {{-- Same-origin frame, so this is an ordinary authenticated request
                         and not a way around the access check. A TIFF arrives here as
                         the PDF it was converted into. --}}
                    <iframe class="arc-viewer"
                            src="{{ route('archive.document.stream', [$document->id, $primary->id]) }}"
                            title="{{ $primary->downloadName() }}"></iframe>
                @endif
            @else
                <div class="arc-card arc-empty">
                    <p class="mb-2">This file type cannot be shown in the browser.</p>
                    <a class="btn btn-sm btn-brand" href="{{ route('archive.document.download', [$document->id, $primary->id]) }}">
                        <i class="bi bi-download"></i> Download it
                    </a>
                </div>
            @endif

            @if ($primary)
                {{-- The text read from the file on screen, page by page, so what a
                     word search or an answer rests on can be checked against the
                     scan beside it. Printed escaped: it is a reading of somebody
                     else's document, never markup. --}}
                <div class="arc-card p-3 mt-3">
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <h2 class="h6 mb-0"><i class="bi bi-stars"></i> What AI read</h2>
                        <span class="arc-muted small text-truncate">{{ $primary->downloadName() }}</span>
                        @php($primaryState = $fileReading[$primary->id] ?? null)
                        @if ($primaryState)
                            <span class="small ms-auto {{ $primaryState->cssClass() }}">
                                <i class="bi {{ $primaryState->icon() }}"></i> {{ $primaryState->label() }}
                            </span>
                        @endif
                    </div>

                    @forelse ($texts as $page)
                        <details class="border-top py-2" @if ($loop->first) open @endif>
                            <summary class="small fw-semibold">
                                Page {{ $page->page }}
                                <span class="arc-muted fw-normal">
                                    · {{ match ($page->source) {
                                        \App\Models\Archive\ArchiveFileText::SOURCE_AI => 'read by AI',
                                        \App\Models\Archive\ArchiveFileText::SOURCE_PDF_TEXT => 'from the PDF\'s own text',
                                        \App\Models\Archive\ArchiveFileText::SOURCE_ARCMATE_OCR => 'ArcMate OCR',
                                        default => $page->source,
                                    } }}
                                    @if ($page->read_at) · {{ $page->read_at->format('Y-m-d H:i') }} @endif
                                </span>
                            </summary>
                            <div class="small mt-2" dir="auto" style="white-space:pre-wrap">{{ trim((string) $page->text) !== '' ? $page->text : '(blank page)' }}</div>
                        </details>
                    @empty
                        <p class="arc-muted small mb-0">
                            @if ($reading->hasText())
                                AI has not read this file, but it has read another file of this
                                document: pick it in the list on the left.
                            @else
                                No page of this file has been read yet, so there is no text to show
                                and a word search cannot find it.
                                @if ($canAsk) Asking a question about it above reads its pages. @endif
                            @endif
                        </p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
@endsection
