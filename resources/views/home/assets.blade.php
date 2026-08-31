@extends('layouts.home')

@section('title', 'My Assets | Samir Group Employee Portal')

@push('head')
<style>
  .assets-head{ display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:26px; flex-wrap:wrap; }
  .assets-head h2{ font-size:26px; font-weight:700; }
  .back-link{
    display:inline-flex; align-items:center; gap:8px;
    font-size:13px; font-weight:600; color:var(--ink-soft); text-decoration:none;
    border:1px solid var(--line); background:#fff; border-radius:10px; padding:9px 15px;
  }
  .back-link:hover{ color:var(--ink); border-color:var(--gray-500); }
  .back-link svg{ width:15px; height:15px; }

  .asset-group{
    background:var(--card); border:1px solid var(--line);
    border-radius:16px; box-shadow:var(--shadow);
    margin-bottom:20px; overflow:hidden;
  }
  .asset-group > header{
    display:flex; align-items:center; gap:10px;
    padding:16px 20px; border-bottom:1px solid var(--line);
  }
  .asset-group > header h3{ font-size:15.5px; font-weight:700; }
  .asset-group > header .count{
    margin-left:auto; font-size:12px; font-weight:700;
    background:var(--bg); color:var(--ink-soft);
    padding:3px 10px; border-radius:20px;
  }
  .asset-group > header svg{ width:20px; height:20px; color:var(--red-600); }

  .asset-table{ width:100%; border-collapse:collapse; }
  .asset-table th{
    text-align:left; font-size:10.5px; font-weight:700; letter-spacing:.6px;
    text-transform:uppercase; color:var(--ink-soft);
    padding:10px 20px; background:var(--bg);
  }
  .asset-table td{ padding:13px 20px; font-size:13.5px; border-top:1px solid var(--line); vertical-align:top; }
  .asset-table tr.returned td{ opacity:.55; }
  .asset-name{ font-weight:600; }
  .asset-sub{ font-size:12px; color:var(--ink-soft); margin-top:2px; }
  .asset-code{ font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px; }
  .pill{
    display:inline-block; font-size:10.5px; font-weight:700; letter-spacing:.3px;
    padding:3px 9px; border-radius:20px; background:var(--bg); color:var(--ink-soft);
  }
  .pill.active{ background:#E9F8EF; color:var(--green); }
  .pill.returned{ background:var(--bg); color:var(--ink-soft); }

  .assets-empty{
    background:var(--card); border:1px solid var(--line); border-radius:16px;
    padding:60px 24px; text-align:center; color:var(--ink-soft);
  }
  .assets-empty svg{ width:38px; height:38px; opacity:.35; margin-bottom:12px; }
  @media (max-width:640px){
    .asset-table thead{ display:none; }
    .asset-table td{ display:block; border-top:none; padding:4px 20px; }
    .asset-table tr{ display:block; border-top:1px solid var(--line); padding:12px 0; }
  }
</style>
@endpush

@section('content')

<div class="assets-head">
    <div>
        <h2>My Assets</h2>
        <p style="color:var(--ink-soft);font-size:14px;margin-top:4px;">
            Everything assigned to you. Read-only &mdash; contact IT to change anything here.
        </p>
    </div>
    <a href="{{ route('home.index') }}" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back to portal
    </a>
</div>

@if(! $employee)
    <div class="assets-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M4 20c.9-3.6 4-6 8-6s7.1 2.4 8 6" stroke-linecap="round"/></svg>
        <p>We could not find your HR record, so there is nothing to show here yet.</p>
    </div>
@else

    @php
        $nothing = $itAssets->isEmpty() && $items->isEmpty()
            && $accessories->isEmpty() && $licenses->isEmpty();
    @endphp

    @if($nothing)
        <div class="assets-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5.5A2.5 2.5 0 0 1 10.5 3h3A2.5 2.5 0 0 1 16 5.5V7" stroke-linecap="round"/></svg>
            <p>Nothing is assigned to you at the moment.</p>
        </div>
    @endif

    {{-- ── IT devices ───────────────────────────────────────── --}}
    @if($itAssets->isNotEmpty())
        <section class="asset-group">
            <header>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="2.5" y="4.5" width="19" height="12" rx="2"/><path d="M2 19.5h20" stroke-linecap="round"/></svg>
                <h3>IT Devices</h3>
                <span class="count">{{ $activeCounts['it_assets'] }} active</span>
            </header>
            <table class="asset-table">
                <thead>
                    <tr><th>Device</th><th>Asset code</th><th>Serial</th><th>Assigned</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($itAssets as $a)
                        <tr class="{{ $a->returned_date ? 'returned' : '' }}">
                            <td>
                                <div class="asset-name">
                                    {{ $a->device?->name ?: trim(($a->device?->manufacturer ?? '').' '.($a->device?->model ?? '')) ?: 'Device' }}
                                </div>
                                @if($a->device?->type)
                                    <div class="asset-sub">{{ ucfirst($a->device->type) }}</div>
                                @endif
                            </td>
                            <td class="asset-code">{{ $a->device?->asset_code ?: '—' }}</td>
                            <td class="asset-code">{{ $a->device?->serial_number ?: '—' }}</td>
                            <td>{{ $a->assigned_date ? \Carbon\Carbon::parse($a->assigned_date)->format('d M Y') : '—' }}</td>
                            <td>
                                @if($a->returned_date)
                                    <span class="pill returned">Returned {{ \Carbon\Carbon::parse($a->returned_date)->format('d M Y') }}</span>
                                @else
                                    <span class="pill active">With you</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- ── Accessories ──────────────────────────────────────── --}}
    @if($accessories->isNotEmpty())
        <section class="asset-group">
            <header>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 8h16l-1.2 11.2A2 2 0 0 1 16.8 21H7.2a2 2 0 0 1-2-1.8L4 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2" stroke-linecap="round"/></svg>
                <h3>Accessories</h3>
                <span class="count">{{ $activeCounts['accessories'] }} active</span>
            </header>
            <table class="asset-table">
                <thead><tr><th>Item</th><th>Category</th><th>Assigned</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach($accessories as $acc)
                        <tr class="{{ $acc->returned_date ? 'returned' : '' }}">
                            <td><div class="asset-name">{{ $acc->accessory?->name ?: '—' }}</div></td>
                            <td>{{ $acc->accessory?->category ?: '—' }}</td>
                            <td>{{ $acc->assigned_date ? \Carbon\Carbon::parse($acc->assigned_date)->format('d M Y') : '—' }}</td>
                            <td>
                                @if($acc->returned_date)
                                    <span class="pill returned">Returned</span>
                                @else
                                    <span class="pill active">With you</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- ── Other issued items ───────────────────────────────── --}}
    @if($items->isNotEmpty())
        <section class="asset-group">
            <header>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3.5 8.5 12 4l8.5 4.5v7L12 20l-8.5-4.5v-7Z" stroke-linejoin="round"/><path d="M12 12v8M3.5 8.5 12 12l8.5-3.5" stroke-linejoin="round"/></svg>
                <h3>Other Items</h3>
                <span class="count">{{ $activeCounts['items'] }} active</span>
            </header>
            <table class="asset-table">
                <thead><tr><th>Item</th><th>Model</th><th>Serial</th><th>Assigned</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach($items as $it)
                        <tr class="{{ $it->returned_date ? 'returned' : '' }}">
                            <td>
                                <div class="asset-name">{{ $it->item_name ?: '—' }}</div>
                                @if($it->item_type)
                                    <div class="asset-sub">{{ ucfirst($it->item_type) }}</div>
                                @endif
                            </td>
                            <td>{{ $it->model ?: '—' }}</td>
                            <td class="asset-code">{{ $it->serial_number ?: '—' }}</td>
                            <td>{{ $it->assigned_date ? \Carbon\Carbon::parse($it->assigned_date)->format('d M Y') : '—' }}</td>
                            <td>
                                @if($it->returned_date)
                                    <span class="pill returned">Returned</span>
                                @else
                                    <span class="pill active">With you</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- ── Software licences ────────────────────────────────── --}}
    @if($licenses->isNotEmpty())
        <section class="asset-group">
            <header>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M7.5 9.5h9M7.5 13h6" stroke-linecap="round"/></svg>
                <h3>Software Licences</h3>
                <span class="count">{{ $activeCounts['licenses'] }}</span>
            </header>
            <table class="asset-table">
                <thead><tr><th>Licence</th><th>Vendor</th><th>Assigned</th><th>Expires</th></tr></thead>
                <tbody>
                    @foreach($licenses as $lic)
                        <tr>
                            <td>
                                <div class="asset-name">{{ $lic->license?->license_name ?: '—' }}</div>
                                @if($lic->license?->license_type)
                                    <div class="asset-sub">{{ $lic->license->license_type }}</div>
                                @endif
                            </td>
                            <td>{{ $lic->license?->vendor ?: '—' }}</td>
                            <td>{{ $lic->assigned_date ? \Carbon\Carbon::parse($lic->assigned_date)->format('d M Y') : '—' }}</td>
                            <td>{{ $lic->license?->expiry_date ? \Carbon\Carbon::parse($lic->license->expiry_date)->format('d M Y') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
@endif

@endsection
