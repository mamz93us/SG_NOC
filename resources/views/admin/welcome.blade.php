@php
    $__layout = (auth()->user()?->useV2Layout() ?? false) ? 'layouts.admin-v2' : 'layouts.admin';
    $__hour   = now()->hour;
    $__greet  = $__hour < 12 ? 'Good morning' : ($__hour < 18 ? 'Good afternoon' : 'Good evening');
    $__first  = explode(' ', trim(auth()->user()->name ?? 'there'))[0];
@endphp
@extends($__layout)

@section('content')
<div class="space-y-6">

    {{-- ─── Hero greeting ─── --}}
    <div class="relative overflow-hidden rounded-2xl p-8 text-white shadow-lg"
         style="background: linear-gradient(135deg, #1e40af 0%, #6d28d9 50%, #be185d 100%);">
        <div class="relative z-10 flex items-center justify-between gap-6 flex-wrap">
            <div>
                <div class="text-sm uppercase tracking-widest text-white/70 font-semibold">SG NOC · Admin Console</div>
                <h1 class="text-3xl sm:text-4xl font-bold mt-2 leading-tight">
                    {{ $__greet }}, {{ $__first }}.
                </h1>
                <p class="text-white/80 mt-2 text-sm sm:text-base">
                    @if(auth()->user()->last_login_at ?? false)
                        Last seen {{ auth()->user()->last_login_at->diffForHumans() }} ·
                    @endif
                    {{ now()->format('l, F j, Y · H:i') }}
                </p>
            </div>
            <div class="hidden sm:flex items-center gap-3">
                <div class="text-right">
                    <div class="text-xs text-white/60 uppercase tracking-wider">Role</div>
                    <div class="text-lg font-semibold">{{ \App\Models\User::roleLabel(auth()->user()->role ?? 'admin') }}</div>
                </div>
                <div class="w-14 h-14 rounded-full bg-white/20 backdrop-blur-sm flex items-center justify-center text-white text-xl font-bold ring-2 ring-white/30">
                    {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                </div>
            </div>
        </div>
        {{-- decorative blobs --}}
        <div class="absolute -top-10 -right-10 w-48 h-48 rounded-full bg-white/10 blur-3xl"></div>
        <div class="absolute -bottom-12 left-1/3 w-56 h-56 rounded-full bg-pink-300/20 blur-3xl"></div>
    </div>

    {{-- ─── KPI Tiles (4-up) ─── --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @include('admin.welcome.kpi-tile', [
            'title' => 'Open Incidents',
            'value' => $kpis['incidents_open'],
            'subtitle' => $kpis['incidents_open'] === null ? null : ($kpis['incidents_open'] > 0 ? 'requires attention' : 'all clear'),
            'icon'  => 'bi-exclamation-triangle-fill',
            'tone'  => 'red',
            'href'  => Route::has('admin.noc.incidents.index') ? route('admin.noc.incidents.index') : null,
            'show'  => $kpis['incidents_open'] !== null,
        ])
        @include('admin.welcome.kpi-tile', [
            'title' => 'Open NOC Events',
            'value' => $kpis['noc_events_open'],
            'subtitle' => $kpis['noc_events_open'] === null ? null : 'live monitoring',
            'icon'  => 'bi-broadcast-pin',
            'tone'  => 'amber',
            'href'  => Route::has('admin.noc.events') ? route('admin.noc.events') : null,
            'show'  => $kpis['noc_events_open'] !== null,
        ])
        @include('admin.welcome.kpi-tile', [
            'title' => 'Pending Forms',
            'value' => $kpis['forms_pending'],
            'subtitle' => $kpis['forms_pending'] === null ? null : 'awaiting review',
            'icon'  => 'bi-ui-checks',
            'tone'  => 'blue',
            'href'  => Route::has('admin.forms.index') ? route('admin.forms.index') : null,
            'show'  => $kpis['forms_pending'] !== null,
        ])
        @include('admin.welcome.kpi-tile', [
            'title'    => 'Identity Sync',
            'value'    => ($kpis['identity_sync_health']['success_pct'] ?? null) !== null
                            ? $kpis['identity_sync_health']['success_pct'] . '%'
                            : '—',
            'subtitle' => isset($kpis['identity_sync_health']['last_run']) && $kpis['identity_sync_health']['last_run']
                            ? 'last run ' . $kpis['identity_sync_health']['last_run']->diffForHumans()
                            : null,
            'icon'     => 'bi-cloud-check-fill',
            'tone'     => 'green',
            'href'     => Route::has('admin.identity.sync-logs') ? route('admin.identity.sync-logs') : null,
            'show'     => $kpis['identity_sync_health'] !== null,
        ])
    </div>

    {{-- ─── Two-column body ─── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        @if($activity->isNotEmpty())
            <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">Recent Activity</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Latest changes across the system</p>
                    </div>
                    @if(Route::has('admin.activity-logs'))
                        <a href="{{ route('admin.activity-logs') }}"
                           class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline whitespace-nowrap">
                            View all →
                        </a>
                    @endif
                </div>
                @include('admin.welcome.activity-feed', ['activity' => $activity])
            </div>
        @else
            <div class="lg:col-span-2"></div>
        @endif

        <div class="space-y-4">
            @include('admin.welcome.branch-health',  ['health' => $branchHealth])
            @include('admin.welcome.quick-actions',  ['actions' => $quickActions])
        </div>

    </div>

</div>
@endsection
