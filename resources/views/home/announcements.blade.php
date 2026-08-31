@extends('layouts.home')

@section('title', 'Announcements | Samir Group Employee Portal')

@push('head')
<style>
  .ann-page-head{ display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:26px; flex-wrap:wrap; }
  .ann-page-head h2{ font-size:26px; font-weight:700; }
  .back-link{
    display:inline-flex; align-items:center; gap:8px;
    font-size:13px; font-weight:600; color:var(--ink-soft); text-decoration:none;
    border:1px solid var(--line); background:#fff; border-radius:10px; padding:9px 15px;
  }
  .back-link:hover{ background:#fff; color:var(--ink); border-color:var(--gray-500); }
  .back-link svg{ width:15px; height:15px; }

  .ann-item{
    background:var(--card);
    border:1px solid var(--line);
    border-left:4px solid var(--gray-500);
    border-radius:16px;
    box-shadow:var(--shadow);
    padding:22px 24px;
    margin-bottom:16px;
  }
  .ann-item.is-urgent{ border-left-color:var(--red-600); }
  .ann-item.is-success{ border-left-color:var(--green); }
  .ann-item.is-unread{ background:#FFFDF7; }
  .ann-item-top{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:9px; }
  .ann-item h3{ font-size:17.5px; font-weight:700; color:var(--ink); }
  .ann-item .ann-body{ font-size:14px; line-height:1.65; color:var(--ink-soft); white-space:pre-wrap; }
  .ann-tag{
    font-size:10.5px; font-weight:700; letter-spacing:.5px; text-transform:uppercase;
    padding:3px 9px; border-radius:20px; background:var(--bg); color:var(--ink-soft);
  }
  .ann-tag.is-urgent{ background:var(--red-100); color:var(--red-700); }
  .ann-tag.is-new{ background:var(--red-600); color:#fff; }
  .ann-when{ font-size:12px; color:var(--gray-500); margin-left:auto; }
  .ann-item .ann-link{
    display:inline-block; margin-top:12px; font-size:13px; font-weight:700;
    color:var(--red-600); text-decoration:none;
  }
  .ann-empty{
    background:var(--card); border:1px solid var(--line); border-radius:16px;
    padding:60px 24px; text-align:center; color:var(--ink-soft);
  }
  .ann-empty svg{ width:38px; height:38px; opacity:.35; margin-bottom:12px; }
</style>
@endpush

@section('content')

<div class="ann-page-head">
    <h2>Announcements</h2>
    <a href="{{ route('home.index') }}" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back to portal
    </a>
</div>

@forelse($announcements as $ann)
    @php $isUnread = ! isset($readIds[$ann->id]); @endphp
    <article class="ann-item {{ $ann->severity === 'urgent' ? 'is-urgent' : ($ann->severity === 'success' ? 'is-success' : '') }} {{ $isUnread ? 'is-unread' : '' }}">
        <div class="ann-item-top">
            @if($ann->isUrgent())
                <span class="ann-tag is-urgent">Important</span>
            @endif
            @if($isUnread)
                <span class="ann-tag is-new">New</span>
            @endif
            @if($ann->pinned)
                <span class="ann-tag">Pinned</span>
            @endif
            @if($ann->published_at)
                <span class="ann-when">{{ $ann->published_at->format('j M Y') }}</span>
            @endif
        </div>

        <h3>{{ $ann->title }}</h3>
        <div class="ann-body">{{ $ann->body }}</div>

        @if($ann->link_url)
            <a class="ann-link" href="{{ $ann->link_url }}" target="_blank" rel="noopener noreferrer">
                {{ $ann->link_label ?: 'Read more' }} &rarr;
            </a>
        @endif
    </article>
@empty
    <div class="ann-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M3 10v4a1.4 1.4 0 0 0 1.4 1.4H6l4.4 3.4V5.2L6 8.6H4.4A1.4 1.4 0 0 0 3 10Z" stroke-linejoin="round"/><path d="M15.5 9a4 4 0 0 1 0 6" stroke-linecap="round"/></svg>
        <p>No announcements right now.</p>
    </div>
@endforelse

@if($announcements->hasPages())
    <div style="margin-top:24px;">{{ $announcements->links() }}</div>
@endif

@endsection

@push('scripts')
<script>
// Opening the archive counts as reading everything on the page.
(function () {
  var ids = @json($announcements->pluck('id')->values());
  if (!ids.length) return;

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  if (!csrf) return;

  fetch(@json(route('home.announcements.read')), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify({ ids: ids.slice(0, 50) })
  }).catch(function () { /* read state is not worth an error toast */ });
})();
</script>
@endpush
