@extends('layouts.home')

@section('title', ($category === 'policy' ? 'IT Policies' : 'Documentation & Manuals').' | Samir Group Employee Portal')

@push('head')
<style>
  .docs-head{ display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:22px; flex-wrap:wrap; }
  .docs-head h2{ font-size:26px; font-weight:700; }
  .docs-head p{ color:var(--ink-soft); font-size:14px; margin-top:4px; }
  .back-link{
    display:inline-flex; align-items:center; gap:8px;
    font-size:13px; font-weight:600; color:var(--ink-soft); text-decoration:none;
    border:1px solid var(--line); background:#fff; border-radius:10px; padding:9px 15px;
  }
  .back-link:hover{ color:var(--ink); border-color:var(--gray-500); }
  .back-link svg{ width:15px; height:15px; }

  .docs-toolbar{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
  .chip{
    display:inline-flex; align-items:center; gap:7px;
    font-size:13px; font-weight:600; text-decoration:none;
    color:var(--ink-soft); background:#fff; border:1px solid var(--line);
    border-radius:20px; padding:8px 15px;
  }
  .chip:hover{ border-color:var(--gray-500); color:var(--ink); }
  .chip.active{ background:var(--ink); border-color:var(--ink); color:#fff; }
  .chip .n{
    font-size:11px; font-weight:700; background:var(--bg); color:var(--ink-soft);
    border-radius:20px; padding:1px 7px;
  }
  .chip.active .n{ background:rgba(255,255,255,.18); color:#fff; }

  .docs-search{ margin-left:auto; display:flex; gap:8px; }
  .docs-search input{
    font-family:inherit; font-size:13.5px; color:var(--ink);
    border:1px solid var(--line); background:#fff; border-radius:10px;
    padding:9px 14px; min-width:230px;
  }
  .docs-search input:focus{ outline:none; border-color:var(--red-600); box-shadow:0 0 0 3px var(--red-100); }
  .docs-search button{
    font-family:inherit; font-size:13px; font-weight:600; cursor:pointer;
    border:1px solid var(--line); background:#fff; color:var(--ink);
    border-radius:10px; padding:9px 15px;
  }
  .docs-search button:hover{ border-color:var(--gray-500); }

  .doc-group{ margin-bottom:30px; }
  .doc-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:18px; }

  .doc-card{
    display:flex; gap:15px; align-items:flex-start;
    background:var(--card); border:1px solid var(--line); border-radius:16px;
    box-shadow:var(--shadow); padding:18px 19px;
    text-decoration:none; color:inherit;
    transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
  }
  .doc-card:hover{ transform:translateY(-3px); box-shadow:var(--shadow-hover); border-color:transparent; }
  .doc-card:focus-visible{ outline:2px solid var(--red-500); outline-offset:3px; }

  .doc-type{
    flex:0 0 auto; width:46px; height:52px; border-radius:9px;
    background:var(--bg); border:1px solid var(--line);
    display:flex; align-items:center; justify-content:center;
    font-size:10.5px; font-weight:800; letter-spacing:.3px; color:var(--ink-soft);
  }
  .doc-type.pdf{ background:var(--red-100); border-color:#F6C9CA; color:var(--red-700); }
  .doc-type.link{ background:#EAF2FE; border-color:#CCDFFB; color:#1B4FA0; }
  /* A play triangle rather than the word VIDEO, and drawn rather than fetched:
     a YouTube thumbnail would be one external request per card. */
  .doc-type.video{ background:#1C1C1E; border-color:#1C1C1E; color:#fff; }
  .doc-type.video svg{ width:20px; height:20px; }

  .doc-body{ min-width:0; flex:1; }
  .doc-body h3{ font-size:15px; font-weight:600; line-height:1.35; }
  .doc-body .ar{ font-family:var(--font-ar); font-size:13px; color:var(--ink-soft); margin-top:2px; direction:rtl; }
  .doc-body p{ font-size:12.5px; color:var(--ink-soft); margin-top:6px; line-height:1.5; }
  .doc-meta{ display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; font-size:11.5px; color:var(--ink-soft); }
  .doc-meta .tag{ background:var(--bg); border-radius:20px; padding:2px 9px; font-weight:600; }
  .doc-meta .tag.pin{ background:var(--red-100); color:var(--red-700); }

  .docs-empty{
    background:var(--card); border:1px solid var(--line); border-radius:16px;
    padding:60px 24px; text-align:center; color:var(--ink-soft);
  }
  .docs-empty svg{ width:38px; height:38px; opacity:.35; margin-bottom:12px; }

  @media (max-width:1080px){ .doc-grid{ grid-template-columns:repeat(2,1fr); } }
  @media (max-width:680px){
    .doc-grid{ grid-template-columns:1fr; }
    .docs-search{ margin-left:0; width:100%; }
    .docs-search input{ flex:1; min-width:0; }
  }
</style>
@endpush

@section('content')

@php
    $isPolicy = $category === 'policy';
@endphp

<div class="docs-head">
    <div>
        <h2>{{ $isPolicy ? 'IT Policies' : 'Documentation &amp; Manuals' }}</h2>
        <p>
            @if($isPolicy)
                The rules for using company IT — read these, they apply to everyone.
            @else
                Manuals, how-to guides and forms published by IT and HR.
            @endif
        </p>
    </div>
    <a href="{{ route('home.index') }}" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back to portal
    </a>
</div>

<div class="docs-toolbar">
    <a class="chip {{ $category === null ? 'active' : '' }}"
       href="{{ route('home.documents', $search !== '' ? ['q' => $search] : []) }}">
        Everything
        <span class="n">{{ $counts->sum() }}</span>
    </a>
    @foreach(\App\Models\PortalDocument::CATEGORIES as $key => $label)
        @if(($counts[$key] ?? 0) > 0)
            <a class="chip {{ $category === $key ? 'active' : '' }}"
               href="{{ route('home.documents', array_filter(['category' => $key, 'q' => $search ?: null])) }}">
                {{ $label }}
                <span class="n">{{ $counts[$key] }}</span>
            </a>
        @endif
    @endforeach

    <form class="docs-search" method="GET" action="{{ route('home.documents') }}" role="search">
        @if($category)
            <input type="hidden" name="category" value="{{ $category }}">
        @endif
        <input type="search" name="q" value="{{ $search }}" placeholder="Search documents…"
               aria-label="Search documents">
        <button type="submit">Search</button>
    </form>
</div>

@if($documents->isEmpty())
    <div class="docs-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M6 3.5h7.5L19 9v11.5H6z" stroke-linejoin="round"/><path d="M13.5 3.5V9H19" stroke-linejoin="round"/></svg>
        <p>
            @if($search !== '')
                Nothing matches &ldquo;{{ $search }}&rdquo;.
            @else
                Nothing published here yet.
            @endif
        </p>
    </div>
@else
    @php
        // Grouped by category when nothing is filtered, so "Everything" still
        // reads as a library rather than one long undifferentiated list.
        $groups = $category
            ? collect([$category => $documents])
            : $documents->groupBy('category')->sortKeys();
    @endphp

    @foreach($groups as $groupKey => $groupDocs)
        <div class="doc-group">
            @if(! $category)
                <p class="section-label">
                    {{ \Illuminate\Support\Str::plural(\App\Models\PortalDocument::CATEGORIES[$groupKey] ?? ucfirst($groupKey)) }}
                </p>
            @endif

            <div class="doc-grid">
                @foreach($groupDocs as $doc)
                    @php
                        // A PDF, an image or a video opens in the portal's own
                        // viewer; a plain link leaves; anything else (Word,
                        // Excel, a zip) has no in-browser rendering worth a
                        // page, so it downloads straight from the card.
                        $external = $doc->sourceType() === 'link';
                        $href = match (true) {
                            $doc->isPreviewable() => route('home.documents.show', $doc),
                            $external => $doc->link_url,
                            default => route('home.documents.download', $doc),
                        };
                        $verb = match (true) {
                            $doc->isVideo() => 'Watch',
                            $doc->isPreviewable() => 'Open',
                            $external => 'Open',
                            default => 'Download',
                        };
                    @endphp
                    <a class="doc-card"
                       href="{{ $href }}"
                       @if($external) target="_blank" rel="noopener noreferrer" @endif
                       aria-label="{{ $verb }} {{ $doc->title }}">
                        <div class="doc-type {{ $doc->isVideo() ? 'video' : ($doc->isPdf() ? 'pdf' : ($external ? 'link' : '')) }}">
                            @if($doc->isVideo())
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.2v13.6L19 12 8 5.2Z"/></svg>
                            @else
                                {{ $doc->typeTag() }}
                            @endif
                        </div>
                        <div class="doc-body">
                            <h3>{{ $doc->title }}</h3>
                            @if($doc->title_ar)
                                <div class="ar">{{ $doc->title_ar }}</div>
                            @endif
                            @if($doc->description)
                                <p>{{ $doc->description }}</p>
                            @endif
                            <div class="doc-meta">
                                @if($doc->pinned)
                                    <span class="tag pin">Must read</span>
                                @endif
                                @if($doc->isVideo())
                                    <span class="tag">Video</span>
                                @endif
                                @if($doc->version)
                                    <span class="tag">v{{ $doc->version }}</span>
                                @endif
                                @if($doc->effective_date)
                                    <span>Effective {{ $doc->effective_date->format('j M Y') }}</span>
                                @endif
                                @if($doc->humanSize())
                                    <span>{{ $doc->humanSize() }}</span>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
@endif

@endsection
