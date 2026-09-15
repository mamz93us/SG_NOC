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
                    <thead>
                        <tr>
                            @foreach ($fields as $field)
                                <th>{{ $field->label() }}</th>
                            @endforeach
                            <th>Scanned</th>
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
