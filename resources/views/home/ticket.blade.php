@extends('layouts.home')

@section('title', __('home_ticket.title_ticket', ['id' => $ticket['id']]) . ' | ' . __('home_ticket.title_suffix'))

@php
    use App\Services\Ticketing\TicketStatus;

    $fmt = function (?string $d) {
        if (! $d) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($d)->locale(app()->getLocale())->translatedFormat('j M Y, H:i');
        } catch (\Throwable) {
            // The API sends no timezone and its format is not guaranteed —
            // show whatever it gave rather than swallowing the field.
            return str_replace('T', ' ', $d);
        }
    };

    $status = $ticket['status_id'];

    // Cancelled and rejected are not a stage on the way to done, they are the
    // end of the road — so they replace the last step rather than sitting on
    // the path.
    $isDead = in_array($status, [TicketStatus::CANCELLED, TicketStatus::REJECTED], true);
    $isDone = in_array($status, [TicketStatus::COMPLETED, TicketStatus::CLOSED], true);
    $needsYou = $status === TicketStatus::WAITING_FOR_USER;

    $steps = [
        ['label' => __('home_ticket.steps.raised'), 'state' => 'done'],
        ['label' => $needsYou ? __('home_ticket.steps.waiting_on_you') : __('home_ticket.steps.with_it'),
         'state' => $isDone || $isDead ? 'done' : 'current'],
        ['label' => $isDead ? ($ticket['status_name'] ?? __('home_ticket.steps.closed')) : __('home_ticket.steps.done'),
         'state' => $isDone ? 'done' : ($isDead ? 'dead' : 'todo')],
    ];

    $pill = match ($status) {
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
  .tk-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:18px; flex-wrap:wrap; margin-bottom:18px; }
  .tk-id{ font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:13px; color:var(--ink-soft); }
  .tk-top h2{ font-size:25px; font-weight:700; line-height:1.25; margin-top:2px; }
  .tk-pills{ display:flex; gap:7px; flex-wrap:wrap; margin-top:11px; }
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

  /* ── Stepper ─────────────────────────────────────────────── */
  .tk-steps{
    display:flex; align-items:flex-start; gap:0;
    background:var(--card); border:1px solid var(--line); border-radius:16px;
    box-shadow:var(--shadow); padding:20px 24px; margin-bottom:18px;
  }
  .tk-step{ flex:1; display:flex; flex-direction:column; align-items:center; gap:8px; position:relative; text-align:center; }
  .tk-step::before, .tk-step::after{
    content:""; position:absolute; top:9px; height:2px; background:var(--line);
  }
  .tk-step::before{ left:0; right:50%; margin-right:11px; }
  .tk-step::after{ left:50%; right:0; margin-left:11px; }
  .tk-step:first-child::before, .tk-step:last-child::after{ display:none; }
  .tk-step.done::before, .tk-step.current::before{ background:var(--green); }
  .tk-step.done::after{ background:var(--green); }
  .tk-step .dot{
    width:20px; height:20px; border-radius:50%; background:#fff;
    border:2px solid var(--line); position:relative; z-index:1;
    display:flex; align-items:center; justify-content:center;
  }
  .tk-step .dot svg{ width:11px; height:11px; color:#fff; }
  .tk-step.done .dot{ background:var(--green); border-color:var(--green); }
  .tk-step.current .dot{ background:#fff; border-color:var(--amber); border-width:5px; }
  .tk-step.dead .dot{ background:var(--red-600); border-color:var(--red-600); }
  .tk-step .lbl{ font-size:12.5px; font-weight:600; color:var(--ink-soft); }
  .tk-step.done .lbl, .tk-step.current .lbl{ color:var(--ink); }
  .tk-step.dead .lbl{ color:var(--red-600); }

  .tk-callout{
    display:flex; gap:11px; align-items:flex-start;
    border-radius:14px; padding:14px 18px; margin-bottom:18px; font-size:13.5px; line-height:1.5;
  }
  .tk-callout svg{ width:18px; height:18px; flex:0 0 auto; margin-top:1px; }
  .tk-callout.warn{ background:#FEF6E7; border:1px solid #F7E0B5; color:#7A5B00; }
  .tk-callout.bad{ background:#FDECEC; border:1px solid #F7CFCF; color:#8C1A1D; }

  /* ── Layout ──────────────────────────────────────────────── */
  .tk-grid{ display:grid; grid-template-columns:minmax(0,1.9fr) minmax(250px,1fr); gap:18px; align-items:start; }
  @media (max-width:900px){ .tk-grid{ grid-template-columns:1fr; } }

  .tk-card{
    background:var(--card); border:1px solid var(--line);
    border-radius:16px; box-shadow:var(--shadow); overflow:hidden; margin-bottom:18px;
  }
  /* Explicit reset as well as the layout's `body >` scoping: this <header> must
     never inherit the page chrome's dark gradient again. */
  .tk-card > header{
    background:none; padding:14px 20px; border-bottom:1px solid var(--line);
    display:flex; align-items:center; gap:9px;
  }
  .tk-card > header h3{
    font-size:11.5px; font-weight:700; letter-spacing:.7px; text-transform:uppercase;
    color:var(--ink-soft);
  }
  .tk-card > header svg{ width:15px; height:15px; color:var(--ink-soft); }
  .tk-card > header .n{
    margin-left:auto; font-size:11px; font-weight:700; color:var(--ink-soft);
    background:var(--bg); border-radius:20px; padding:2px 9px;
  }
  .tk-card .body{ padding:18px 20px; font-size:14px; line-height:1.6; }
  .tk-card .body p{ white-space:pre-wrap; word-break:break-word; }
  .tk-card .body.empty{ color:var(--ink-soft); font-size:13.5px; }

  /* ── Facts ───────────────────────────────────────────────── */
  .tk-facts{ font-size:13.5px; }
  .tk-facts .row{ display:flex; gap:12px; padding:11px 20px; border-top:1px solid var(--line); }
  .tk-facts .row:first-child{ border-top:none; }
  .tk-facts .k{ color:var(--ink-soft); flex:0 0 42%; }
  .tk-facts .v{ font-weight:600; word-break:break-word; flex:1; }
  .tk-facts .v.muted{ font-weight:400; color:var(--ink-soft); }

  /* ── Timeline ────────────────────────────────────────────── */
  .tk-tl{ padding:6px 20px 18px; }
  .tk-step-row{ display:flex; gap:14px; position:relative; padding:14px 0 0; }
  .tk-step-row::before{
    content:""; position:absolute; left:5px; top:0; bottom:0; width:2px; background:var(--line);
  }
  .tk-step-row:first-child::before{ top:20px; }
  .tk-step-row:last-child::before{ bottom:auto; height:20px; }
  .tk-step-row .marker{
    width:12px; height:12px; border-radius:50%; background:var(--red-600);
    border:2px solid var(--card); flex:0 0 auto; margin-top:5px; position:relative; z-index:1;
  }
  .tk-step-row .content{ flex:1; min-width:0; }
  .tk-step-row .sh{ display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:baseline; }
  .tk-step-row .sn{ font-weight:700; font-size:13.5px; }
  .tk-step-row .sd{ font-size:12px; color:var(--ink-soft); }
  .tk-step-row .sc{ font-size:13.5px; margin-top:4px; white-space:pre-wrap; line-height:1.55; word-break:break-word; }
  .tk-step-row .sw{ font-size:12px; color:var(--ink-soft); margin-top:5px; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
  .tk-step-row .sw svg{ width:13px; height:13px; }

  /* ── Attachments ─────────────────────────────────────────── */
  .tk-files{ display:flex; flex-wrap:wrap; gap:8px; padding:16px 20px; }
  .tk-file{
    display:inline-flex; align-items:center; gap:8px;
    background:var(--bg); border:1px solid var(--line); border-radius:10px;
    padding:8px 12px; font-size:12.5px; max-width:100%;
  }
  .tk-file svg{ width:15px; height:15px; color:var(--ink-soft); flex:0 0 auto; }
  .tk-file span{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .tk-note{ padding:11px 20px; font-size:12px; color:var(--ink-soft); border-top:1px solid var(--line); background:var(--bg); }
  /* ── Reply box ───────────────────────────────────────────── */
  .tk-reply{ padding:16px 20px; border-top:1px solid var(--line); }
  .tk-reply textarea{
    width:100%; min-height:84px; resize:vertical; font:inherit; font-size:13.5px;
    border:1px solid var(--line); border-radius:10px; padding:11px 13px; color:var(--ink);
    background:#fff;
  }
  .tk-reply textarea:focus{ outline:2px solid var(--red-600); outline-offset:-1px; border-color:transparent; }
  .tk-reply-row{ display:flex; align-items:center; gap:10px; margin-top:10px; flex-wrap:wrap; }
  .tk-attach{
    display:inline-flex; align-items:center; gap:7px; cursor:pointer;
    font-size:12.5px; font-weight:600; color:var(--ink-soft);
    border:1px solid var(--line); border-radius:10px; padding:8px 13px; background:#fff;
  }
  .tk-attach:hover{ border-color:var(--gray-500); color:var(--ink); }
  .tk-attach svg{ width:14px; height:14px; }
  .tk-attach input{ display:none; }
  .tk-attach-name{ font-size:12.5px; color:var(--ink-soft); }
  .tk-send{
    margin-left:auto; font:inherit; font-size:13px; font-weight:600; cursor:pointer;
    background:var(--red-600); border:1px solid var(--red-600); color:#fff;
    border-radius:10px; padding:9px 18px;
  }
  .tk-send:hover{ filter:brightness(1.07); }
  .tk-flash{ border-radius:10px; padding:11px 14px; font-size:13px; margin-bottom:12px; }
  .tk-flash.ok{ background:#E9F8EF; border:1px solid #BFE8CF; color:#14532D; }
  .tk-flash.bad{ background:#FDECEC; border:1px solid #F7CFCF; color:#8C1A1D; }
  .tk-err{ font-size:12.5px; color:var(--red-600); margin-top:6px; }

  /* ── RTL mirrors ─────────────────────────────────────────── */
  html[dir="rtl"] .tk-step::before{ left:50%; right:0; margin-right:0; margin-left:11px; }
  html[dir="rtl"] .tk-step::after{ left:0; right:50%; margin-left:0; margin-right:11px; }
  html[dir="rtl"] .tk-card > header .n{ margin-left:0; margin-right:auto; }
  html[dir="rtl"] .tk-step-row::before{ left:auto; right:5px; }
  html[dir="rtl"] .tk-send{ margin-left:0; margin-right:auto; }
</style>
@endpush

@section('content')

<div class="tk-top">
    <div>
        <div class="tk-id">#{{ $ticket['id'] }}</div>
        <h2>{{ $ticket['title'] ?: __('home_ticket.untitled') }}</h2>
        <div class="tk-pills">
            <span class="tk-pill {{ $pill }}">{{ $ticket['status_name'] ?? '—' }}</span>
            @if($ticket['priority_name'])
                <span class="tk-pill">{{ __('home_ticket.priority_suffix', ['priority' => $ticket['priority_name']]) }}</span>
            @endif
            @if($ticket['type_name'])
                <span class="tk-pill">{{ $ticket['type_name'] }}</span>
            @endif
        </div>
    </div>
    <a href="{{ route('home.tickets.index') }}" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        {{ __('home_ticket.back_link') }}
    </a>
</div>

<div class="tk-steps" role="group" aria-label="{{ __('home_ticket.progress_aria') }}">
    @foreach($steps as $step)
        <div class="tk-step {{ $step['state'] }}">
            <span class="dot">
                @if($step['state'] === 'done')
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" aria-hidden="true"><path d="M5 12.5 10 17 19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @endif
            </span>
            <span class="lbl">{{ $step['label'] }}</span>
        </div>
    @endforeach
</div>

@if($needsYou)
    <div class="tk-callout warn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 8.5v4.5M12 16.2v.05" stroke-linecap="round"/><path d="M10.3 4.3 2.9 17.1A2 2 0 0 0 4.6 20h14.8a2 2 0 0 0 1.7-2.9L13.7 4.3a2 2 0 0 0-3.4 0Z" stroke-linejoin="round"/></svg>
        <span><strong>{{ __('home_ticket.callout.waiting_title') }}</strong> {{ __('home_ticket.callout.waiting_body') }}</span>
    </div>
@elseif($isDead)
    <div class="tk-callout bad">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6" stroke-linecap="round"/></svg>
        <span>{!! __('home_ticket.callout.dead', ['status' => '<strong>'.e(mb_strtolower($ticket['status_name'] ?? __('home_ticket.steps.closed'))).'</strong>']) !!}</span>
    </div>
@endif

<div class="tk-grid">
    <div>
        <section class="tk-card">
            <header>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M5 4.5h14v15l-4-3H5z" stroke-linejoin="round"/></svg>
                <h3>{{ __('home_ticket.sections.description') }}</h3>
            </header>
            <div class="body {{ $ticket['description'] ? '' : 'empty' }}">
                <p>{{ $ticket['description'] ?: __('home_ticket.description_empty') }}</p>
            </div>
        </section>

        @if($ticket['assigned_task_desc'])
            <section class="tk-card">
                <header>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 6.5h16M4 12h16M4 17.5h10" stroke-linecap="round"/></svg>
                    <h3>{{ __('home_ticket.sections.it_note') }}</h3>
                </header>
                <div class="body"><p>{{ $ticket['assigned_task_desc'] }}</p></div>
            </section>
        @endif

        @if($ticket['attachments'])
            <section class="tk-card">
                <header>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M19 11.5 12.5 18a4 4 0 0 1-5.6-5.6l7-7a2.8 2.8 0 0 1 4 4l-7 7a1.6 1.6 0 0 1-2.2-2.2l6.3-6.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <h3>{{ __('home_ticket.sections.attachments') }}</h3>
                    <span class="n">{{ count($ticket['attachments']) }}</span>
                </header>
                <div class="tk-files">
                    @foreach($ticket['attachments'] as $a)
                        <span class="tk-file" title="{{ $a['name'] }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M14 3v5h5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" stroke-linejoin="round"/></svg>
                            <span>{{ $a['name'] }}</span>
                        </span>
                    @endforeach
                </div>
                {{-- The API returns an attachmentApiId but publishes no endpoint
                     to fetch the bytes, so these are named, not linked. --}}
                <div class="tk-note">{{ __('home_ticket.attachments_note') }}</div>
            </section>
        @endif

        <section class="tk-card">
            <header>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <h3>{{ __('home_ticket.sections.progress') }}</h3>
                @if($ticket['timeline'])
                    <span class="n">{{ count($ticket['timeline']) }}</span>
                @endif
            </header>
            @if($ticket['timeline'])
                <div class="tk-tl">
                    @foreach($ticket['timeline'] as $step)
                        <div class="tk-step-row">
                            <span class="marker"></span>
                            <div class="content">
                                <div class="sh">
                                    <span class="sn">{{ $step['status_name'] ?? __('home_ticket.timeline_update_fallback') }}</span>
                                    <span class="sd">{{ $fmt($step['created_at']) ?? '' }}</span>
                                </div>
                                @if($step['comments'])
                                    <div class="sc">{{ $step['comments'] }}</div>
                                @endif
                                @if($step['attachment_name'] || $step['assigned_to'])
                                    <div class="sw">
                                        @if($step['attachment_name'])
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M19 11.5 12.5 18a4 4 0 0 1-5.6-5.6l7-7a2.8 2.8 0 0 1 4 4l-7 7a1.6 1.6 0 0 1-2.2-2.2l6.3-6.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            <span>{{ $step['attachment_name'] }}</span>
                                        @endif
                                        @if($step['attachment_name'] && $step['assigned_to']) &middot; @endif
                                        @if($step['assigned_to'])
                                            <span>{{ __('home_ticket.with_prefix', ['name' => $step['assigned_to']]) }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="body empty">
                    {{ __('home_ticket.timeline_empty') }}
                </div>
            @endif

            {{-- A reply is a timeline entry, so the box lives at the foot of
                 the history rather than in a card of its own. Closed tickets
                 get no box: the comment would land somewhere nobody reads. --}}
            @if(! $isDone && ! $isDead)
                <div class="tk-reply">
                    @if(session('ticketSuccess'))
                        <div class="tk-flash ok">{{ session('ticketSuccess') }}</div>
                    @endif
                    @if(session('ticketError'))
                        <div class="tk-flash bad">{{ session('ticketError') }}</div>
                    @endif

                    <form method="POST" action="{{ route('home.tickets.comment', $ticket['id']) }}"
                          enctype="multipart/form-data" id="tkReplyForm">
                        @csrf
                        <label for="tkComment" class="sr-only" style="position:absolute;left:-9999px;">{{ __('home_ticket.reply.label') }}</label>
                        <textarea name="comment" id="tkComment" maxlength="5000" required
                                  placeholder="{{ __('home_ticket.reply.placeholder') }}">{{ old('comment') }}</textarea>
                        @error('comment') <div class="tk-err">{{ $message }}</div> @enderror

                        <div class="tk-reply-row">
                            <label class="tk-attach">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M19 11.5 12.5 18a4 4 0 0 1-5.6-5.6l7-7a2.8 2.8 0 0 1 4 4l-7 7a1.6 1.6 0 0 1-2.2-2.2l6.3-6.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ __('home_ticket.reply.attach_file') }}
                                <input type="file" name="attachment" id="tkAttach">
                            </label>
                            <span class="tk-attach-name" id="tkAttachName"></span>
                            <button type="submit" class="tk-send">{{ __('home_ticket.reply.send') }}</button>
                        </div>
                        @error('attachment') <div class="tk-err">{{ $message }}</div> @enderror
                        <div class="tk-attach-name" style="margin-top:8px;">{{ __('home_ticket.reply.attach_hint') }}</div>
                    </form>
                </div>
            @endif
        </section>
    </div>

    <aside>
        <section class="tk-card">
            <header>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8v.05" stroke-linecap="round"/></svg>
                <h3>{{ __('home_ticket.sections.details') }}</h3>
            </header>
            <div class="tk-facts">
                <div class="row"><span class="k">{{ __('home_ticket.facts.category') }}</span><span class="v">{{ $ticket['category_name'] ?? '—' }}</span></div>
                <div class="row"><span class="k">{{ __('home_ticket.facts.subcategory') }}</span><span class="v">{{ $ticket['subcategory_name'] ?? '—' }}</span></div>
                <div class="row"><span class="k">{{ __('home_ticket.facts.raised') }}</span><span class="v">{{ $fmt($ticket['created_at']) ?? '—' }}</span></div>
                <div class="row">
                    <span class="k">{{ __('home_ticket.facts.last_update') }}</span>
                    <span class="v {{ $fmt($ticket['updated_at']) ? '' : 'muted' }}">
                        {{ $fmt($ticket['updated_at']) ?? __('home_ticket.facts.no_updates_yet') }}
                    </span>
                </div>
                <div class="row">
                    <span class="k">{{ __('home_ticket.facts.handled_by') }}</span>
                    <span class="v {{ $ticket['engineer_name'] ? '' : 'muted' }}">
                        {{ $ticket['engineer_name'] ?? __('home_ticket.facts.not_assigned') }}
                    </span>
                </div>
                @if($ticket['department_name'])
                    <div class="row"><span class="k">{{ __('home_ticket.facts.team') }}</span><span class="v">{{ $ticket['department_name'] }}</span></div>
                @endif
                @if($ticket['estimated_finish_at'] && ! $isDone && ! $isDead)
                    <div class="row"><span class="k">{{ __('home_ticket.facts.target_date') }}</span><span class="v">{{ $fmt($ticket['estimated_finish_at']) }}</span></div>
                @endif
                @if($ticket['completed_at'])
                    <div class="row"><span class="k">{{ __('home_ticket.facts.completed') }}</span><span class="v">{{ $fmt($ticket['completed_at']) }}</span></div>
                @endif
                @if($ticket['closed_at'])
                    <div class="row"><span class="k">{{ __('home_ticket.facts.closed') }}</span><span class="v">{{ $fmt($ticket['closed_at']) }}</span></div>
                @endif
            </div>
        </section>
    </aside>
</div>
@endsection

@push('scripts')
<script>
(function () {
  var input = document.getElementById('tkAttach');
  var name  = document.getElementById('tkAttachName');
  var form  = document.getElementById('tkReplyForm');
  if (input && name) {
    input.addEventListener('change', function () {
      name.textContent = input.files && input.files[0] ? input.files[0].name : '';
    });
  }
  // The API call can take a few seconds; a second click would post twice.
  if (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('.tk-send');
      if (btn) { btn.disabled = true; btn.textContent = @json(__('home_ticket.reply.sending')); }
    });
  }
})();
</script>
@endpush
