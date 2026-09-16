@extends('layouts.archive')

@section('title', 'Archives')

@section('content')
    <div class="d-flex align-items-end justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">Archives</h1>
            <p class="arc-muted small mb-0">The document archives you have been given access to.</p>
        </div>
    </div>

    <div class="row g-3">
        @foreach ($archives as $archive)
            <div class="col-md-6 col-xl-4">
                <a href="{{ route('archive.show', $archive->slug) }}" class="text-decoration-none">
                    <div class="arc-card p-3 h-100">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div>
                                <div class="fw-semibold" style="color:var(--ink)">{{ $archive->displayName() }}</div>
                                @if ($archive->description)
                                    <div class="arc-muted small">{{ $archive->description }}</div>
                                @endif
                            </div>

                            {{-- What state the archive is in matters to the person reading it:
                                 a mirrored archive is still being fed by ArcMate, a read-only one
                                 is history and will never change again. --}}
                            @if ($archive->mode === \App\Models\Archive\Archive::MODE_MIRROR)
                                <span class="badge badge-soft">Mirrored</span>
                            @elseif ($archive->mode === \App\Models\Archive\Archive::MODE_READ_ONLY)
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">History</span>
                            @else
                                <span class="badge" style="background:var(--green);color:#fff">In use</span>
                            @endif
                        </div>

                        <div class="d-flex gap-3 mt-3 arc-muted small">
                            <span><i class="bi bi-file-earmark-text"></i> {{ number_format($archive->document_count) }} documents</span>
                            <span><i class="bi bi-paperclip"></i> {{ number_format($archive->file_count) }} files</span>
                        </div>

                        @unless ($archive->readable)
                            {{-- The ArcMate "Test" project: stored with Encrypt=1, so its bytes
                                 are ciphertext only ArcMate can undo. Said plainly rather than
                                 letting someone click into an archive of unopenable files. --}}
                            <div class="alert alert-warning py-2 px-2 small mt-3 mb-0">
                                <i class="bi bi-lock"></i> {{ $archive->unreadable_reason ?: 'ArcMate stored this archive encrypted; its files cannot be opened here.' }}
                            </div>
                        @endunless
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    @if ($canManage)
        {{-- An absolute link, because this is a different host: the settings are
             in the NOC now, and this portal is for reading, filing and asking. --}}
        <p class="arc-muted small mt-4 mb-0">
            Archives, access and the ArcMate connection are set up in the NOC:
            <a href="{{ route('admin.archive.index') }}">Document Archive settings</a>
        </p>
    @endif
@endsection
