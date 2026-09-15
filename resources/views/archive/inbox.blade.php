@extends('layouts.archive')

@section('title', 'Inbox')

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ route('archive.index') }}" class="arc-muted text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Archives
        </a>
        <span class="arc-muted">/</span>
        <span class="fw-semibold">Inbox</span>

        <span class="ms-auto arc-muted small">
            {{ number_format($items->total()) }} waiting to be filed
        </span>
    </div>

    @if ($archives->isEmpty())
        <div class="arc-card arc-empty">
            <i class="bi bi-inbox" style="font-size:1.5rem"></i>
            <p class="mt-2 mb-1">There is nowhere for you to file documents yet.</p>
            <p class="small mb-0">
                Filing needs “Add documents” on an archive that this portal owns.
                Ask IT for the archives you scan into.
            </p>
        </div>
    @else

    <div class="row g-3">
        <div class="col-lg-4">
            {{-- ── Sending something in ────────────────────────────── --}}
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-3">Add scans</h2>

                <form method="POST" action="{{ route('archive.inbox.store') }}" enctype="multipart/form-data">
                    @csrf

                    <label class="form-label small arc-muted mb-1">Archive (optional)</label>
                    <select class="form-select form-select-sm mb-2" name="archive_id">
                        <option value="">Decide when filing</option>
                        @foreach ($archives as $archive)
                            <option value="{{ $archive->id }}">{{ $archive->displayName() }}</option>
                        @endforeach
                    </select>

                    <input type="file" class="form-control form-control-sm" name="files[]" multiple required
                           accept="{{ collect($accepted)->map(fn ($e) => '.'.$e)->implode(',') }}">

                    <div class="form-text small">
                        {{ strtoupper(implode(', ', $accepted)) }}, up to
                        {{ number_format($maxKilobytes / 1024) }} MB each, {{ $maxFiles }} at a time.
                    </div>

                    <button class="btn btn-brand btn-sm w-100 mt-2">Upload</button>
                </form>

                <p class="arc-muted small mb-0 mt-2">
                    Each scan is read in the background, usually within a minute, and its
                    index fields are filled in for you to check. You can file one before
                    that finishes if it is urgent.
                </p>
            </div>

            {{-- ── Other ways things arrive ────────────────────────── --}}
            <div class="arc-card p-3 mb-3">
                <h2 class="h6 mb-2">Other ways to send a scan</h2>
                <p class="arc-muted small mb-0">
                    Scanning straight from a copier, from a scan folder, or from this browser
                    is not set up yet. Until then, scan to your own PC and upload it here.
                </p>
            </div>

            @if ($recentlyFiled->isNotEmpty())
                <div class="arc-card p-3">
                    <h2 class="h6 mb-2">Just filed</h2>
                    <ul class="list-unstyled small mb-0">
                        @foreach ($recentlyFiled as $done)
                            <li class="mb-1 text-truncate">
                                <i class="bi bi-check2 text-success"></i>
                                @if ($done->archive_document_id)
                                    <a href="{{ route('archive.document', $done->archive_document_id) }}"
                                       class="text-decoration-none">{{ $done->displayName() }}</a>
                                @else
                                    {{ $done->displayName() }}
                                @endif
                                <span class="arc-muted">{{ $done->filed_at?->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- ── Waiting ─────────────────────────────────────────────── --}}
        <div class="col-lg-8">
            <div class="arc-card p-3">
                @if ($items->isEmpty())
                    <div class="arc-empty">
                        <i class="bi bi-inbox" style="font-size:1.5rem"></i>
                        <p class="mt-2 mb-0">Nothing waiting.</p>
                        <p class="small mb-0">Upload a scan and it appears here.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table arc-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Scan</th>
                                    <th>Arrived</th>
                                    <th>Read</th>
                                    <th>Suggested</th>
                                    <th class="text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($items as $item)
                                    <tr>
                                        <td>
                                            <a href="{{ route('archive.inbox.edit', $item) }}"
                                               class="fw-semibold text-decoration-none text-break">
                                                {{ $item->displayName() }}
                                            </a>
                                            <div class="arc-muted small">
                                                {{ $item->sourceLabel() }}
                                                @if ($item->pages)
                                                    · {{ $item->pages }} page(s)
                                                @endif
                                                @if ($item->size)
                                                    · {{ number_format($item->size / 1048576, 1) }} MB
                                                @endif
                                            </div>
                                        </td>

                                        <td class="arc-muted small">
                                            {{ $item->received_at?->diffForHumans() ?? '—' }}
                                        </td>

                                        <td class="small">
                                            @switch ($item->ai_status)
                                                @case (\App\Models\Archive\ArchiveInboxItem::AI_DONE)
                                                    <span style="color:var(--green)"><i class="bi bi-check2"></i> read</span>
                                                    @break
                                                @case (\App\Models\Archive\ArchiveInboxItem::AI_READING)
                                                    <span class="arc-muted"><i class="bi bi-hourglass-split"></i> reading…</span>
                                                    @break
                                                @case (\App\Models\Archive\ArchiveInboxItem::AI_FAILED)
                                                    {{-- Said plainly, and it does not stop anybody filing by hand. --}}
                                                    <span style="color:var(--amber)" title="{{ $item->error }}">
                                                        <i class="bi bi-exclamation-triangle"></i> could not read
                                                    </span>
                                                    @break
                                                @default
                                                    <span class="arc-muted">queued</span>
                                            @endswitch
                                        </td>

                                        <td class="small">
                                            @if ($item->suggestedArchive)
                                                {{ $item->suggestedArchive->displayName() }}
                                                @if ($item->ai_confidence !== null)
                                                    <span class="arc-muted">({{ $item->ai_confidence }}%)</span>
                                                @endif
                                            @elseif ($item->archive)
                                                {{ $item->archive->displayName() }}
                                            @else
                                                <span class="arc-muted">—</span>
                                            @endif
                                        </td>

                                        <td class="text-end text-nowrap">
                                            <a class="btn btn-sm btn-brand" href="{{ route('archive.inbox.edit', $item) }}">File</a>
                                            <form method="POST" action="{{ route('archive.inbox.discard', $item) }}"
                                                  class="d-inline m-0"
                                                  onsubmit="return confirm('Discard {{ $item->displayName() }}? The scan is deleted.')">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-secondary">Discard</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">{{ $items->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    @endif
@endsection
