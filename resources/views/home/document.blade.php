@extends('layouts.home')

@section('title', $doc->title.' | Samir Group Employee Portal')

@push('head')
<style>
  .viewer-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:18px; margin-bottom:18px; flex-wrap:wrap; }
  .viewer-head h2{ font-size:23px; font-weight:700; line-height:1.3; }
  .viewer-head .ar{ font-family:var(--font-ar); font-size:15px; color:var(--ink-soft); margin-top:3px; direction:rtl; }
  .viewer-head p.desc{ color:var(--ink-soft); font-size:14px; margin-top:8px; max-width:70ch; line-height:1.55; }

  .viewer-meta{ display:flex; gap:9px; flex-wrap:wrap; margin-top:11px; font-size:11.5px; color:var(--ink-soft); align-items:center; }
  .viewer-meta .tag{ background:var(--bg); border-radius:20px; padding:3px 10px; font-weight:600; }
  .viewer-meta .tag.cat{ background:var(--ink); color:#fff; }
  .viewer-meta .tag.pin{ background:var(--red-100); color:var(--red-700); }

  .viewer-actions{ display:flex; gap:9px; flex-wrap:wrap; flex-shrink:0; }
  .vbtn{
    display:inline-flex; align-items:center; gap:8px;
    font-family:inherit; font-size:13px; font-weight:600; text-decoration:none; cursor:pointer;
    color:var(--ink); background:#fff; border:1px solid var(--line); border-radius:10px; padding:9px 15px;
  }
  .vbtn:hover{ border-color:var(--gray-500); }
  .vbtn.primary{ background:var(--red-600); border-color:var(--red-600); color:#fff; }
  .vbtn.primary:hover{ filter:brightness(1.07); }
  .vbtn svg{ width:15px; height:15px; }

  .viewer-stage{
    background:var(--card); border:1px solid var(--line); border-radius:16px;
    box-shadow:var(--shadow); overflow:hidden;
  }
  /* Tall enough to read a page of A4 without a second scrollbar fight, but
     never taller than the window. */
  .pdf-frame{ display:block; width:100%; height:min(78vh, 1000px); border:0; background:var(--bg); }
  .img-wrap{ padding:18px; text-align:center; background:var(--bg); }
  .img-wrap img{ max-width:100%; border-radius:10px; box-shadow:var(--shadow); }

  /* 16:9, which is what YouTube serves. aspect-ratio keeps it correct at every
     width without the old padding-top hack. */
  .video-wrap{ position:relative; width:100%; aspect-ratio:16/9; background:#000; }
  .video-wrap iframe{ position:absolute; inset:0; width:100%; height:100%; border:0; }

  .viewer-fallback{
    display:flex; align-items:center; gap:11px;
    border-top:1px solid var(--line); padding:12px 18px;
    font-size:12.5px; color:var(--ink-soft); background:var(--bg);
  }
  .viewer-fallback svg{ width:16px; height:16px; flex-shrink:0; }
  .viewer-fallback a{ font-weight:700; color:var(--red-600); }
</style>
@endpush

@section('content')

<div class="viewer-head">
    <div>
        <h2>{{ $doc->title }}</h2>
        @if($doc->title_ar)
            <div class="ar">{{ $doc->title_ar }}</div>
        @endif
        <div class="viewer-meta">
            <span class="tag cat">{{ $doc->categoryLabel() }}</span>
            @if($doc->pinned)
                <span class="tag pin">Must read</span>
            @endif
            @if($doc->version)
                <span class="tag">v{{ $doc->version }}</span>
            @endif
            @if($doc->effective_date)
                <span>Effective {{ $doc->effective_date->format('j M Y') }}</span>
            @endif
            @if($doc->humanSize())
                <span>{{ $doc->typeTag() }} · {{ $doc->humanSize() }}</span>
            @endif
        </div>
        @if($doc->description)
            <p class="desc">{{ $doc->description }}</p>
        @endif
    </div>

    <div class="viewer-actions">
        <a href="{{ route('home.documents', ['category' => $doc->category]) }}" class="vbtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Back
        </a>
        @if($doc->isVideo())
            <a href="{{ $doc->youtubeWatchUrl() }}" target="_blank" rel="noopener noreferrer" class="vbtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 4.5h5.5V10M19 5l-7.5 7.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M18.5 14v4.5a1.5 1.5 0 0 1-1.5 1.5H5.5A1.5 1.5 0 0 1 4 18.5V7A1.5 1.5 0 0 1 5.5 5.5H10" stroke-linecap="round"/></svg>
                Watch on YouTube
            </a>
        @else
            <a href="{{ route('home.documents.file', $doc) }}" target="_blank" rel="noopener noreferrer" class="vbtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 4.5h5.5V10M19 5l-7.5 7.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M18.5 14v4.5a1.5 1.5 0 0 1-1.5 1.5H5.5A1.5 1.5 0 0 1 4 18.5V7A1.5 1.5 0 0 1 5.5 5.5H10" stroke-linecap="round"/></svg>
                Open in a new tab
            </a>
            <a href="{{ route('home.documents.download', $doc) }}" class="vbtn primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 4v10.5M7.5 10.5 12 15l4.5-4.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 19h14" stroke-linecap="round"/></svg>
                Download
            </a>
        @endif
    </div>
</div>

<div class="viewer-stage">
    @if($doc->isVideo())
        <div class="video-wrap">
            {{-- youtube-nocookie, and only ever an id that came out of
                 PortalDocument::youtubeId() — a pasted URL never reaches an
                 iframe src unparsed. --}}
            <iframe src="{{ $doc->youtubeEmbedUrl() }}"
                    title="{{ $doc->title }}"
                    loading="lazy"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                    referrerpolicy="strict-origin-when-cross-origin"
                    allowfullscreen></iframe>
        </div>
    @elseif($doc->isImage())
        <div class="img-wrap">
            <img src="{{ route('home.documents.file', $doc) }}" alt="{{ $doc->title }}">
        </div>
    @else
        {{-- Same-origin, so the app's X-Frame-Options: SAMEORIGIN allows it.
             Some mobile browsers still refuse to render a PDF in a frame, which
             is what the note underneath is for. --}}
        <iframe class="pdf-frame"
                src="{{ route('home.documents.file', $doc) }}#view=FitH"
                title="{{ $doc->title }}"></iframe>
        <div class="viewer-fallback">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8.2v.05" stroke-linecap="round"/></svg>
            <span>
                Nothing showing above? Some phone browsers will not display a PDF in
                a page —
                <a href="{{ route('home.documents.file', $doc) }}" target="_blank" rel="noopener noreferrer">open it in a new tab</a>
                instead.
            </span>
        </div>
    @endif
</div>

@endsection
