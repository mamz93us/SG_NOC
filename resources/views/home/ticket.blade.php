@extends('layouts.home')

@section('title', 'Ticket #' . $ticket['id'] . ' | Samir Group Employee Portal')

@php
    use App\Services\Ticketing\TicketStatus;
    $fmt = fn (?string $d) => $d ? \Illuminate\Support\Str::of($d)->replace('T', ' ')->limit(16, '') : '—';
    $pill = match ($ticket['status_id']) {
        TicketStatus::OPEN => 's-open',
        TicketStatus::IN_PROGRESS => 's-progress',
        TicketStatus::WAITING_FOR_USER => 's-waiting',
        TicketStatus::COMPLETED => 's-done',
        TicketStatus::REJECTED => 's-rejected',
        default => 's-closed',
    };
@endphp

@push('head')
<style>
  .tk-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:18px; margin-bottom:22px; flex-wrap:wrap; }
  .tk-head h2{ font-size:23px; font-weight:700; }
  .tk-head .id{ font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:14px; color:var(--ink-soft); }
  .back-link{
    display:inline-flex; align-items:center; gap:8px;
    font-size:13px; font-weight:600; color:var(--ink-soft); text-decoration:none;
    border:1px solid var(--line); background:#fff; border-radius:10px; padding:9px 15px;
  }
  .back-link:hover{ color:var(--ink); border-color:var(--gray-500); }
  .back-link svg{ width:15px; height:15px; }

  .tk-pill{
    display:inline-block; font-size:10.5px; font-weight:700; letter-spacing:.3px;
    padding:4px 11px; border-radius:20px; background:var(--bg); color:var(--ink-soft);
  }
  .tk-pill.s-open{ background:#E8F0FE; color:#1A56DB; }
  .tk-pill.s-progress{ background:#E6F6FA; color:#0E7490; }
  .tk-pill.s-waiting{ background:#FEF3E2; color:var(--amber); }
  .tk-pill.s-done{ background:#E9F8EF; color:var(--green); }
  .tk-pill.s-closed{ background:var(--bg); color:var(--ink-soft); }
  .tk-pill.s-rejected{ background:#FDECEC; color:var(--red-600); }

  .tk-grid{ display:grid; grid-template-columns:minmax(0,2fr) minmax(240px,1fr); gap:18px; align-items:start; }
  @media (max-width:860px){ .tk-grid{ grid-template-columns:1fr; } }

  .tk-card{
    background:var(--card); border:1px solid var(--line);
    border-radius:16px; box-shadow:var(--shadow); overflow:hidden; margin-bottom:18px;
  }
  .tk-card > header{ padding:14px 20px; border-bottom:1px solid var(--line); }
  .tk-card > header h3{ font-size:14px; font-weight:700; }
  .tk-card .body{ padding:18px 20px; font-size:14px; }
  .tk-card .body p{ white-space:pre-wrap; }

  .tk-facts{ font-size:13.5px; }
  .tk-facts div.row{ display:flex; gap:12px; padding:9px 20px; border-top:1px solid var(--line); }
  .tk-facts div.row:first-child{ border-top:none; }
  .tk-facts .k{ color:var(--ink-soft); flex:0 0 44%; }
  .tk-facts .v{ font-weight:600; word-break:break-word; }

  .tk-step{ display:flex; gap:12px; padding:14px 20px; border-top:1px solid var(--line); }
  .tk-step:first-child{ border-top:none; }
  .tk-step .dot{ width:9px; height:9px; border-radius:50%; background:var(--red-600); margin-top:6px; flex:0 0 auto; }
  .tk-step .sh{ display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; }
  .tk-step .sn{ font-weight:700; font-size:13.5px; }
  .tk-step .sd{ font-size:12px; color:var(--ink-soft); }
  .tk-step .sc{ font-size:13.5px; margin-top:4px; white-space:pre-wrap; }
  .tk-step .sw{ font-size:12px; color:var(--ink-soft); margin-top:4px; }

  .tk-file{ display:flex; align-items:center; gap:9px; padding:11px 20px; border-top:1px solid var(--line); font-size:13.5px; }
  .tk-file:first-child{ border-top:none; }
  .tk-file svg{ width:16px; height:16px; color:var(--ink-soft); flex:0 0 auto; }
  .tk-note{ padding:11px 20px; font-size:12px; color:var(--ink-soft); border-top:1px solid var(--line); background:var(--bg); }
</style>
@endpush

@section('content')

<div class="tk-head">
    <div>
        <div class="id">#{{ $ticket['id'] }}</div>
        <h2>{{ $ticket['title'] }}</h2>
        <div style="margin-top:8px;display:flex;gap:7px;flex-wrap:wrap;">
            <span class="tk-pill {{ $pill }}">{{ $ticket['status_name'] ?? '—' }}</span>
            @if($ticket['priority_name'])
                <span class="tk-pill">{{ $ticket['priority_name'] }} priority</span>
            @endif
            @if($ticket['type_name'])
                <span class="tk-pill">{{ $ticket['type_name'] }}</span>
            @endif
        </div>
    </div>
    <a href="{{ route('home.tickets.index') }}" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        All my tickets
    </a>
</div>

<div class="tk-grid">
    <div>
        <section class="tk-card">
            <header><h3>What you asked for</h3></header>
            <div class="body"><p>{{ $ticket['description'] ?: '—' }}</p></div>
        </section>

        @if($ticket['assigned_task_desc'])
            <section class="tk-card">
                <header><h3>Note from IT</h3></header>
                <div class="body"><p>{{ $ticket['assigned_task_desc'] }}</p></div>
            </section>
        @endif

        @if($ticket['attachments'])
            <section class="tk-card">
                <header><h3>Attachments</h3></header>
                @foreach($ticket['attachments'] as $a)
                    <div class="tk-file">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M14 3v5h5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>{{ $a['name'] }}</span>
                    </div>
                @endforeach
                {{-- The API gives an attachmentApiId but no endpoint to fetch
                     the bytes, so these are named, not linked. --}}
                <div class="tk-note">Files stay in the ticketing system — ask IT if you need a copy.</div>
            </section>
        @endif

        <section class="tk-card">
            <header><h3>Progress</h3></header>
            @forelse($ticket['timeline'] as $step)
                <div class="tk-step">
                    <span class="dot"></span>
                    <div style="flex:1;min-width:0;">
                        <div class="sh">
                            <span class="sn">{{ $step['status_name'] ?? 'Update' }}</span>
                            <span class="sd">{{ $fmt($step['created_at']) }}</span>
                        </div>
                        @if($step['comments'])<div class="sc">{{ $step['comments'] }}</div>@endif
                        @if($step['attachment_name'])<div class="sw">Attached: {{ $step['attachment_name'] }}</div>@endif
                        @if($step['assigned_to'])<div class="sw">With {{ $step['assigned_to'] }}</div>@endif
                    </div>
                </div>
            @empty
                <div class="body" style="color:var(--ink-soft);">Nothing has been logged on this ticket yet.</div>
            @endforelse
        </section>
    </div>

    <aside>
        <section class="tk-card">
            <header><h3>Details</h3></header>
            <div class="tk-facts">
                <div class="row"><span class="k">Category</span><span class="v">{{ $ticket['category_name'] ?? '—' }}</span></div>
                <div class="row"><span class="k">Sub-category</span><span class="v">{{ $ticket['subcategory_name'] ?? '—' }}</span></div>
                <div class="row"><span class="k">Raised</span><span class="v">{{ $fmt($ticket['created_at']) }}</span></div>
                <div class="row"><span class="k">Last update</span><span class="v">{{ $fmt($ticket['updated_at']) }}</span></div>
                <div class="row"><span class="k">Handled by</span><span class="v">{{ $ticket['engineer_name'] ?? 'Not yet assigned' }}</span></div>
                @if($ticket['estimated_finish_at'])
                    <div class="row"><span class="k">Target date</span><span class="v">{{ $fmt($ticket['estimated_finish_at']) }}</span></div>
                @endif
                @if($ticket['completed_at'])
                    <div class="row"><span class="k">Completed</span><span class="v">{{ $fmt($ticket['completed_at']) }}</span></div>
                @endif
                @if($ticket['closed_at'])
                    <div class="row"><span class="k">Closed</span><span class="v">{{ $fmt($ticket['closed_at']) }}</span></div>
                @endif
            </div>
        </section>

        @if($ticket['status_id'] === TicketStatus::WAITING_FOR_USER)
            <section class="tk-card">
                <div class="body" style="color:var(--amber);font-weight:600;">
                    This ticket is waiting on you. Reply to IT so they can carry on.
                </div>
            </section>
        @endif
    </aside>
</div>
@endsection
