@extends('layouts.archive')

@section('title', $archive->displayName())

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('archive.index') }}" class="arc-muted text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Archives
        </a>
        <span class="arc-muted">/</span>
        <span class="fw-semibold">{{ $archive->displayName() }}</span>
    </div>

    <form method="GET" action="{{ route('archive.show', $archive->slug) }}" class="arc-card p-3 mb-3">
        <div class="row g-2">
            @foreach ($fields as $field)
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small arc-muted mb-1">{{ $field->label() }}</label>

                    @if ($field->isDate() || $field->isNumber())
                        {{-- Two boxes, because "March 2024" and "over 10,000" are ranges.
                             These compare on the typed columns, not on the text. --}}
                        <div class="d-flex gap-1">
                            <input type="{{ $field->isDate() ? 'date' : 'number' }}" step="any"
                                   class="form-control form-control-sm"
                                   name="f[{{ $field->key }}][from]" placeholder="From"
                                   value="{{ $criteria['filters'][$field->key]['from'] ?? '' }}">
                            <input type="{{ $field->isDate() ? 'date' : 'number' }}" step="any"
                                   class="form-control form-control-sm"
                                   name="f[{{ $field->key }}][to]" placeholder="To"
                                   value="{{ $criteria['filters'][$field->key]['to'] ?? '' }}">
                        </div>
                    @elseif ($field->isList() && ! empty($choices[$field->key]))
                        <select class="form-select form-select-sm" name="f[{{ $field->key }}]">
                            <option value="">Any</option>
                            @foreach ($choices[$field->key] as $choice)
                                <option value="{{ $choice }}" @selected(($criteria['filters'][$field->key] ?? null) === $choice)>{{ $choice }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="text" class="form-control form-control-sm"
                               name="f[{{ $field->key }}]"
                               value="{{ is_string($criteria['filters'][$field->key] ?? null) ? $criteria['filters'][$field->key] : '' }}"
                               placeholder="Starts with…">
                    @endif
                </div>
            @endforeach

            <div class="col-sm-6 col-lg-3">
                <label class="form-label small arc-muted mb-1">Scanned between</label>
                <div class="d-flex gap-1">
                    <input type="date" class="form-control form-control-sm" name="from" value="{{ $criteria['from'] }}">
                    <input type="date" class="form-control form-control-sm" name="to" value="{{ $criteria['to'] }}">
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <label class="form-label small arc-muted mb-1">Words in the document</label>
                <input type="text" class="form-control form-control-sm" name="q" value="{{ $criteria['words'] }}"
                       placeholder="Only where the pages have been read">
            </div>
        </div>

        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-brand btn-sm px-3" type="submit"><i class="bi bi-search"></i> Search</button>
            @if ($hasFilters)
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('archive.show', $archive->slug) }}">Clear</a>
            @endif
        </div>
    </form>

    @if ($canAsk)
        {{-- Asking across the archives this person may search. Distinct from the
             search above: the form is exact and searches index fields, this reads
             what has actually been read. Both matter, which is why both are here
             and the note says which is which. --}}
        <div class="arc-card p-3 mb-3">
            <form id="askArchiveForm" class="d-flex gap-2 align-items-start flex-wrap">
                <input type="text" class="form-control form-control-sm" id="askArchiveQuestion"
                       maxlength="1000" style="min-width:260px;flex:1"
                       placeholder="Ask: which invoices from ACME are over 10,000?">
                <button class="btn btn-brand btn-sm" type="submit" id="askArchiveSend">
                    <i class="bi bi-stars"></i> Ask
                </button>
            </form>

            <div id="askArchiveAnswer" class="small mt-2 d-none"></div>

            <p class="arc-muted small mb-0 mt-2">
                The search above is exact and covers every document. Asking searches the
                index too, but anything about words inside the pages only covers pages
                that have been read.
            </p>
        </div>

        <script>
        (function () {
            const form   = document.getElementById('askArchiveForm');
            const box    = document.getElementById('askArchiveQuestion');
            const send   = document.getElementById('askArchiveSend');
            const answer = document.getElementById('askArchiveAnswer');

            form.addEventListener('submit', function (event) {
                event.preventDefault();

                const question = box.value.trim();
                if (question === '') { return; }

                send.disabled = true;
                answer.classList.remove('d-none');
                answer.textContent = 'Searching…';

                fetch(@json(route('archive.ask')), {
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
                    answer.textContent = data.answer || data.error || 'No answer came back.';

                    // An answer that called no tool is an answer from nothing —
                    // the model talking rather than searching. Said plainly,
                    // because these are invoice numbers and amounts.
                    if (data.answer && (!data.used_tools || data.used_tools.length === 0)) {
                        const warn = document.createElement('div');
                        warn.className = 'small mt-2';
                        warn.style.color = 'var(--amber)';
                        warn.textContent = 'This answer did not search the archive — check it against the search above.';
                        answer.appendChild(warn);
                    }
                })
                .catch(function () {
                    answer.textContent = 'The AI service could not answer that just now.';
                })
                .finally(function () { send.disabled = false; });
            });
        })();
        </script>
    @endif

    <div class="arc-card p-0">
        @if ($documents->isEmpty())
            <div class="arc-empty">
                @if ($hasFilters)
                    <i class="bi bi-search" style="font-size:1.5rem"></i>
                    <p class="mt-2 mb-0">Nothing matched.</p>
                @elseif ($archive->document_count === 0)
                    <i class="bi bi-hourglass-split" style="font-size:1.5rem"></i>
                    <p class="mt-2 mb-0">Nothing has been copied from ArcMate yet.</p>
                    <p class="small mb-0">The sync runs every five minutes and works through the backlog.</p>
                @else
                    <p class="mb-0">Search above to find a document.</p>
                @endif
            </div>
        @else
            <div class="table-responsive">
                <table class="table arc-table mb-0">
                    {{-- Sortable headings. fullUrlWithQuery keeps whatever search is
                         already applied, so sorting narrows results rather than
                         resetting the form. --}}
                    @php
                        $sortHead = function (string $key, string $label) use ($criteria) {
                            $active = ($criteria["sort"] ?? "date") === $key;
                            // Clicking the active column flips it; a new column starts
                            // in the order that is useful for it - newest first for a
                            // date, A-Z for a name.
                            $next = $active
                                ? (($criteria["dir"] ?? "desc") === "asc" ? "desc" : "asc")
                                : ($key === "date" ? "desc" : "asc");
                            $icon = $active
                                ? ' <i class="bi bi-caret-' . (($criteria["dir"] ?? "desc") === "asc" ? "up" : "down") . '-fill"></i>'
                                : "";

                            // e() on the label: it is printed unescaped so the caret can be markup, and
                            // the label itself comes from ArcMate's arcDesign.xml on the share.
                            return [request()->fullUrlWithQuery(["sort" => $key, "dir" => $next, "page" => null]), e($label) . $icon, $active];
                        };
                    @endphp
                    <thead>
                        <tr>
                            @foreach ($fields as $field)
                                @php([$href, $html, $active] = $sortHead($field->key, $field->label()))
                                <th>
                                    <a href="{{ $href }}" class="text-decoration-none {{ $active ? "fw-bold" : "arc-muted" }}">
                                        {!! $html !!}
                                    </a>
                                </th>
                            @endforeach
                            @php([$href, $html, $active] = $sortHead("date", "Scanned"))
                            <th>
                                <a href="{{ $href }}" class="text-decoration-none {{ $active ? "fw-bold" : "arc-muted" }}">
                                    {!! $html !!}
                                </a>
                            </th>
                            <th class="text-end">Files</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            @php($map = $document->valueMap())
                            <tr>
                                @foreach ($fields as $loopIndex => $field)
                                    <td>
                                        @if ($loop->first)
                                            <a href="{{ route('archive.document', $document->id) }}" class="fw-semibold text-decoration-none">
                                                {{ $map[$field->key] ?? '—' }}
                                            </a>
                                        @else
                                            {{ $map[$field->key] ?? '—' }}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="arc-muted">{{ $document->captured_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="text-end arc-muted">{{ $document->file_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-3">
                {{ $documents->links() }}
            </div>
        @endif
    </div>
@endsection
