@extends('layouts.archive')

@section('title', 'File '.$item->displayName())

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ route('archive.inbox') }}" class="arc-muted text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Inbox
        </a>
        <span class="arc-muted">/</span>
        <span class="fw-semibold text-break">{{ $item->displayName() }}</span>
    </div>

    <div class="row g-3">
        {{-- ── What is being filed ─────────────────────────────────── --}}
        <div class="col-lg-7">
            @if ($item->isPdf())
                {{-- Same origin, so an ordinary authenticated request rather than a
                     way around the gate. --}}
                <iframe class="arc-viewer" src="{{ route('archive.inbox.preview', $item) }}"
                        title="{{ $item->displayName() }}"></iframe>
            @elseif ($item->isImage())
                <div class="arc-card p-3 text-center">
                    <img src="{{ route('archive.inbox.preview', $item) }}"
                         alt="{{ $item->displayName() }}" class="img-fluid">
                </div>
            @else
                <div class="arc-card arc-empty">
                    <i class="bi bi-file-earmark-image" style="font-size:1.5rem"></i>
                    <p class="mt-2 mb-2">
                        This is a TIFF, which browsers cannot show. It is filed and viewed
                        normally once it is in the archive.
                    </p>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('archive.inbox.preview', $item) }}">
                        <i class="bi bi-download"></i> Open it
                    </a>
                </div>
            @endif
        </div>

        {{-- ── Where it goes ───────────────────────────────────────── --}}
        <div class="col-lg-5">
            @if ($archives->isEmpty())
                <div class="arc-card arc-empty">
                    You do not have “Add documents” on any archive this portal owns.
                </div>
            @else

            {{-- Choosing the archive reloads, because each archive has its own
                 fields and its own AI suggestions to show against them. --}}
            <div class="arc-card p-3 mb-3">
                <form method="GET" action="{{ route('archive.inbox.edit', $item) }}">
                    <label class="form-label small arc-muted mb-1">File into</label>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" name="archive" onchange="this.form.submit()">
                            <option value="">Choose an archive…</option>
                            @foreach ($archives as $option)
                                <option value="{{ $option->id }}" @selected($archive?->id === $option->id)>
                                    {{ $option->displayName() }}
                                </option>
                            @endforeach
                        </select>
                        <noscript><button class="btn btn-sm btn-outline-secondary">Go</button></noscript>
                    </div>
                </form>

                @if ($item->ai_archive_id && ! $archive)
                    <p class="small mb-0 mt-2">
                        <i class="bi bi-stars"></i>
                        AI suggests {{ $item->suggestedArchive?->displayName() }}.
                    </p>
                @endif
            </div>

            @if ($archive)
                <form method="POST" action="{{ route('archive.inbox.file', $item) }}">
                    @csrf
                    <input type="hidden" name="archive_id" value="{{ $archive->id }}">

                    <div class="arc-card p-3 mb-3">
                        <h2 class="h6 mb-3">{{ $archive->displayName() }}</h2>

                        @if ($item->ai_status === \App\Models\Archive\ArchiveInboxItem::AI_QUEUED)
                            <p class="arc-muted small">
                                <i class="bi bi-hourglass-split"></i>
                                This scan has not been read yet — reload in a moment for
                                suggested values, or type them in now.
                            </p>
                        @endif

                        @forelse ($archive->fields as $field)
                            @php($suggestion = $item->suggestionFor($field->key))
                            <div class="mb-3">
                                <label class="form-label small arc-muted mb-1">
                                    {{ $field->label() }}
                                    @if ($field->required)
                                        <span class="text-danger">*</span>
                                    @endif
                                </label>

                                @if ($field->isList() && $field->optionList() !== [])
                                    <select class="form-select form-select-sm" name="values[{{ $field->key }}]">
                                        <option value="">—</option>
                                        @foreach ($field->optionList() as $option)
                                            <option value="{{ $option }}"
                                                @selected(old('values.'.$field->key, $suggestion['value'] ?? '') === $option)>
                                                {{ $option }}
                                            </option>
                                        @endforeach
                                    </select>
                                @else
                                    <input
                                        type="{{ $field->isDate() ? 'date' : ($field->isNumber() ? 'number' : 'text') }}"
                                        @if ($field->isNumber()) step="any" @endif
                                        class="form-control form-control-sm"
                                        name="values[{{ $field->key }}]"
                                        maxlength="250"
                                        value="{{ old('values.'.$field->key, $suggestion['value'] ?? '') }}">
                                @endif

                                @if ($suggestion)
                                    {{-- Marked as AI's, with where it was read: these are
                                         invoice numbers, and a value taken on trust is the
                                         thing this whole design exists to avoid. --}}
                                    <div class="form-text small">
                                        <i class="bi bi-stars"></i>
                                        Suggested by AI, {{ $suggestion['confidence'] }}% sure
                                        @if ($suggestion['page'])
                                            · read on page {{ $suggestion['page'] }}
                                        @endif
                                    </div>
                                @endif

                                @if (isset($duplicates[$field->key]))
                                    <div class="small mt-1" style="color:var(--amber)">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        Already filed with that {{ $field->label() }}:
                                        @foreach ($duplicates[$field->key] as $existing)
                                            <a href="{{ route('archive.document', $existing->id) }}"
                                               target="_blank" rel="noopener">{{ $existing->title() }}</a>@if (! $loop->last), @endif
                                        @endforeach
                                        {{-- A warning, not a block: credit notes and re-issues
                                             legitimately repeat a number. --}}
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="arc-muted small">This archive has no index fields.</p>
                        @endforelse
                    </div>

                    {{-- ── Several sheets, one document ────────────────── --}}
                    @if ($alsoWaiting->isNotEmpty())
                        <div class="arc-card p-3 mb-3">
                            <h2 class="h6 mb-2">File these with it</h2>
                            <p class="arc-muted small">
                                A scanner that makes a file per sheet, or an invoice with its
                                delivery note behind it, is one document.
                            </p>

                            @foreach ($alsoWaiting as $other)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="also[]"
                                           value="{{ $other->id }}" id="also{{ $other->id }}">
                                    <label class="form-check-label small text-break" for="also{{ $other->id }}">
                                        {{ $other->displayName() }}
                                        <span class="arc-muted">{{ $other->received_at?->diffForHumans() }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="d-flex gap-2">
                        <button class="btn btn-brand btn-sm px-3">File it</button>
                        <a class="btn btn-outline-secondary btn-sm" href="{{ route('archive.inbox') }}">Cancel</a>
                    </div>
                </form>
            @else
                <div class="arc-card arc-empty">
                    Choose an archive above to see its fields.
                </div>
            @endif

            @endif
        </div>
    </div>
@endsection
