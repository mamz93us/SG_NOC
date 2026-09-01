@extends('layouts.home')

@section('title', 'My Tickets | Samir Group Employee Portal')

@php
    use App\Services\Ticketing\TicketStatus;
    $labels = TicketStatus::labels();
    $fmt = fn (?string $d) => $d ? \Illuminate\Support\Str::of($d)->replace('T', ' ')->limit(16, '') : '—';
@endphp

@push('head')
<style>
  .tk-head{ display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:26px; flex-wrap:wrap; }
  .tk-head h2{ font-size:26px; font-weight:700; }
  .back-link{
    display:inline-flex; align-items:center; gap:8px;
    font-size:13px; font-weight:600; color:var(--ink-soft); text-decoration:none;
    border:1px solid var(--line); background:#fff; border-radius:10px; padding:9px 15px;
  }
  .back-link:hover{ color:var(--ink); border-color:var(--gray-500); }
  .back-link svg{ width:15px; height:15px; }

  .tk-stats{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:22px; }
  .tk-stat{
    background:var(--card); border:1px solid var(--line); border-radius:16px;
    box-shadow:var(--shadow); padding:18px 20px;
  }
  .tk-stat .n{ font-size:32px; font-weight:800; line-height:1.1; }
  .tk-stat .n.live{ color:var(--red-600); }
  .tk-stat .l{ font-size:11px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; color:var(--ink-soft); margin-bottom:4px; }
  .tk-stat .s{ font-size:12px; color:var(--ink-soft); margin-top:4px; }

  .tk-filters{ display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px; }
  .tk-filter{
    font-size:12.5px; font-weight:600; text-decoration:none;
    border:1px solid var(--line); background:#fff; color:var(--ink-soft);
    border-radius:20px; padding:7px 14px;
  }
  .tk-filter:hover{ border-color:var(--gray-500); color:var(--ink); }
  .tk-filter.on{ background:var(--ink); border-color:var(--ink); color:#fff; }
  .tk-filter .c{ opacity:.65; margin-left:4px; }

  .tk-list{ display:flex; flex-direction:column; gap:12px; }
  .tk-item{
    display:block; text-decoration:none; color:inherit;
    background:var(--card); border:1px solid var(--line); border-radius:14px;
    box-shadow:var(--shadow); padding:16px 18px;
  }
  .tk-item:hover{ border-color:var(--gray-500); }
  .tk-item .top{ display:flex; align-items:baseline; gap:10px; flex-wrap:wrap; }
  .tk-item .id{ font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px; color:var(--ink-soft); }
  .tk-item .ti{ font-weight:700; font-size:15px; }
  .tk-item .meta{ font-size:12.5px; color:var(--ink-soft); margin-top:6px; display:flex; gap:14px; flex-wrap:wrap; }

  .tk-pill{
    display:inline-block; font-size:10.5px; font-weight:700; letter-spacing:.3px;
    padding:3px 10px; border-radius:20px; background:var(--bg); color:var(--ink-soft);
    margin-left:auto;
  }
  .tk-pill.s-open{ background:#E8F0FE; color:#1A56DB; }
  .tk-pill.s-progress{ background:#E6F6FA; color:#0E7490; }
  .tk-pill.s-waiting{ background:#FEF3E2; color:var(--amber); }
  .tk-pill.s-done{ background:#E9F8EF; color:var(--green); }
  .tk-pill.s-closed{ background:var(--bg); color:var(--ink-soft); }
  .tk-pill.s-rejected{ background:#FDECEC; color:var(--red-600); }

  .tk-empty{
    background:var(--card); border:1px solid var(--line); border-radius:16px;
    padding:60px 24px; text-align:center; color:var(--ink-soft);
  }
  .tk-empty svg{ width:38px; height:38px; opacity:.35; margin-bottom:12px; }
</style>
@endpush

@php
    // One place deciding the pill's look, so the list and the detail page agree.
    $pill = fn (?int $s) => match ($s) {
        TicketStatus::OPEN => 's-open',
        TicketStatus::IN_PROGRESS => 's-progress',
        TicketStatus::WAITING_FOR_USER => 's-waiting',
        TicketStatus::COMPLETED => 's-done',
        TicketStatus::REJECTED => 's-rejected',
        default => 's-closed',
    };
@endphp

@section('content')

<div class="tk-head">
    <div>
        <h2>My Tickets</h2>
        <p style="color:var(--ink-soft);font-size:14px;margin-top:4px;">
            Everything you have raised with IT, and where each one stands.
        </p>
    </div>
    <a href="{{ route('home.index') }}" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back to portal
    </a>
</div>

@if($error)
    <div class="tk-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" stroke-linecap="round"/></svg>
        <p>{{ $error }}</p>
    </div>
@else

<div class="tk-stats">
    <div class="tk-stat">
        <div class="l">Still open</div>
        <div class="n live">{{ $summary['live'] }}</div>
        <div class="s">Being worked on, or waiting for you</div>
    </div>
    <div class="tk-stat">
        <div class="l">All time</div>
        <div class="n">{{ $summary['total'] }}</div>
        <div class="s">Tickets you have raised</div>
    </div>
    @foreach([TicketStatus::WAITING_FOR_USER => 'Needs you', TicketStatus::COMPLETED => 'Completed'] as $sid => $lab)
        <div class="tk-stat">
            <div class="l">{{ $lab }}</div>
            <div class="n">{{ $summary['by_status'][$sid] ?? 0 }}</div>
            <div class="s">{{ $labels[$sid] }}</div>
        </div>
    @endforeach
</div>

<div class="tk-filters">
    <a href="{{ route('home.tickets.index') }}"
       class="tk-filter {{ $status === TicketStatus::ALL ? 'on' : '' }}">
        All <span class="c">{{ $summary['total'] }}</span>
    </a>
    @foreach($labels as $id => $label)
        <a href="{{ route('home.tickets.index', ['status' => $id]) }}"
           class="tk-filter {{ $status === $id ? 'on' : '' }}">
            {{ $label }} <span class="c">{{ $summary['by_status'][$id] ?? 0 }}</span>
        </a>
    @endforeach
</div>

@if(! $tickets)
    <div class="tk-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18" stroke-linecap="round"/></svg>
        <p>
            @if($status === TicketStatus::ALL)
                You have not raised any tickets yet.
            @else
                Nothing with this status.
            @endif
        </p>
    </div>
@else
    <div class="tk-list">
        @foreach($tickets as $t)
            <a class="tk-item" href="{{ route('home.tickets.show', $t['id']) }}">
                <div class="top">
                    <span class="id">#{{ $t['id'] }}</span>
                    <span class="ti">{{ $t['title'] }}</span>
                    <span class="tk-pill {{ $pill($t['status_id']) }}">{{ $t['status_name'] ?? '—' }}</span>
                </div>
                <div class="meta">
                    <span>{{ $t['category_name'] ?? '—' }}@if($t['subcategory_name']) &middot; {{ $t['subcategory_name'] }}@endif</span>
                    <span>Raised {{ $fmt($t['created_at']) }}</span>
                    @if($t['engineer_name'])
                        <span>With {{ $t['engineer_name'] }}</span>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
@endif

@endif
@endsection
