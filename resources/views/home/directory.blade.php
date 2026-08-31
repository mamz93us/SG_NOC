@extends('layouts.home')

@section('title', 'Employees Directory | Samir Group Employee Portal')

@push('head')
<style>
  .dir-head{ display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:22px; flex-wrap:wrap; }
  .dir-head h2{ font-size:26px; font-weight:700; }
  .dir-head p{ color:var(--ink-soft); font-size:14px; margin-top:4px; }
  .back-link{
    display:inline-flex; align-items:center; gap:8px;
    font-size:13px; font-weight:600; color:var(--ink-soft); text-decoration:none;
    border:1px solid var(--line); background:#fff; border-radius:10px; padding:9px 15px;
  }
  .back-link:hover{ color:var(--ink); border-color:var(--gray-500); }
  .back-link svg{ width:15px; height:15px; }

  .dir-filters{
    background:var(--card); border:1px solid var(--line); border-radius:16px;
    box-shadow:var(--shadow); padding:16px 18px; margin-bottom:22px;
  }
  .dir-filters form{ display:flex; gap:10px; flex-wrap:wrap; }
  .dir-filters input[type="search"], .dir-filters select{
    font-family:var(--font-sans); font-size:14px; color:var(--ink);
    background:#fff; border:1px solid var(--line); border-radius:10px;
    padding:10px 13px;
  }
  .dir-filters input[type="search"]{ flex:1; min-width:220px; }
  .dir-filters select{ min-width:180px; }
  .dir-filters input:focus, .dir-filters select:focus{
    outline:none; border-color:var(--red-600); box-shadow:0 0 0 3px var(--red-100);
  }
  .dir-count{ font-size:13px; color:var(--ink-soft); margin-bottom:14px; }

  .dir-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:14px; }
  .person{
    background:var(--card); border:1px solid var(--line); border-radius:14px;
    box-shadow:var(--shadow); padding:16px 18px;
    display:flex; gap:14px; align-items:flex-start;
    transition:transform .18s ease, box-shadow .18s ease;
  }
  .person:hover{ transform:translateY(-2px); box-shadow:var(--shadow-hover); }
  .avatar{
    width:44px; height:44px; border-radius:50%; flex-shrink:0;
    background:var(--red-100); color:var(--red-600);
    display:flex; align-items:center; justify-content:center;
    font-weight:700; font-size:15px; letter-spacing:.3px;
  }
  .person-body{ min-width:0; flex:1; }
  .person-name{ font-weight:700; font-size:15px; line-height:1.3; }
  .person-title{ font-size:12.5px; color:var(--ink-soft); margin-top:2px; }
  .person-branch{
    display:inline-block; margin-top:7px;
    font-size:10.5px; font-weight:700; letter-spacing:.4px; text-transform:uppercase;
    background:var(--bg); color:var(--ink-soft); padding:3px 9px; border-radius:20px;
  }
  .person-contact{ margin-top:10px; display:flex; flex-direction:column; gap:5px; }
  .person-contact a{
    display:inline-flex; align-items:center; gap:7px;
    font-size:12.5px; color:var(--ink); text-decoration:none;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  .person-contact a:hover{ color:var(--red-600); }
  .person-contact svg{ width:14px; height:14px; flex-shrink:0; color:var(--gray-500); }

  .dir-empty{
    background:var(--card); border:1px solid var(--line); border-radius:16px;
    padding:60px 24px; text-align:center; color:var(--ink-soft);
  }
  .dir-empty svg{ width:38px; height:38px; opacity:.35; margin-bottom:12px; }

  /* Laravel's paginator ships Tailwind classes this page does not load. */
  .dir-pagination svg{ width:16px; height:16px; }
  .dir-pagination{ margin-top:22px; font-size:13.5px; }
</style>
@endpush

@section('content')

<div class="dir-head">
    <div>
        <h2>Employees Directory</h2>
        <p>Search staff, extensions and email across the group.</p>
    </div>
    <a href="{{ route('home.index') }}" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back to portal
    </a>
</div>

<div class="dir-filters">
    <form method="GET" action="{{ route('home.directory') }}">
        <input type="search" name="q" value="{{ $q }}" placeholder="Name, job title, extension or email…" autofocus>
        <select name="branch">
            <option value="">All branches</option>
            @foreach($branches as $b)
                <option value="{{ $b->id }}" @selected((string) $branchId === (string) $b->id)>{{ $b->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary">Search</button>
        @if($q !== '' || ($branchId !== null && $branchId !== ''))
            <a href="{{ route('home.directory') }}" class="back-link">Clear</a>
        @endif
    </form>
</div>

<p class="dir-count">
    {{ number_format($contacts->total()) }}
    {{ \Illuminate\Support\Str::plural('person', $contacts->total()) }}
    @if($q !== '') matching &ldquo;{{ $q }}&rdquo; @endif
</p>

@if($contacts->isEmpty())
    <div class="dir-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5" stroke-linecap="round"/></svg>
        <p>Nobody matches that search.</p>
    </div>
@else
    <div class="dir-grid">
        @foreach($contacts as $contact)
            @php
                $name = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));
                $initials = strtoupper(mb_substr($contact->first_name ?? '', 0, 1).mb_substr($contact->last_name ?? '', 0, 1));
            @endphp
            <article class="person">
                <div class="avatar">{{ $initials ?: '?' }}</div>
                <div class="person-body">
                    <div class="person-name">{{ $name ?: '—' }}</div>
                    @if($contact->job_title)
                        <div class="person-title">{{ $contact->job_title }}</div>
                    @endif
                    @if($contact->branch)
                        <span class="person-branch">{{ $contact->branch->name }}</span>
                    @endif

                    <div class="person-contact">
                        @if($contact->phone)
                            {{-- tel: so a softphone or mobile can dial straight from the page. --}}
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact->phone) }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M6.5 3.5h3l1.5 4-2 1.5a12 12 0 0 0 6 6l1.5-2 4 1.5v3a1.5 1.5 0 0 1-1.7 1.5A17 17 0 0 1 5 5.2 1.5 1.5 0 0 1 6.5 3.5Z" stroke-linejoin="round"/></svg>
                                {{ $contact->phone }}
                            </a>
                        @endif
                        @if($contact->email)
                            <a href="mailto:{{ $contact->email }}" title="{{ $contact->email }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6 8.5-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ $contact->email }}
                            </a>
                        @endif
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    @if($contacts->hasPages())
        <div class="dir-pagination">{{ $contacts->onEachSide(1)->links() }}</div>
    @endif
@endif

@endsection
