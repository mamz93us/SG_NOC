@php
    $__layout = (auth()->user()?->useV2Layout() ?? false) ? 'layouts.admin-v2' : 'layouts.admin';
    $__hour   = now()->hour;
    $__greet  = $__hour < 12 ? 'Good morning' : ($__hour < 18 ? 'Good afternoon' : 'Good evening');
    $__first  = explode(' ', trim(auth()->user()->name ?? 'there'))[0];
@endphp
@extends($__layout)

@section('content')
<div class="space-y-6">

    {{-- ─── Greeting ─── --}}
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100">
                {{ $__greet }}, {{ $__first }}
            </h1>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                @if(auth()->user()->last_login_at ?? false)
                    Last seen {{ auth()->user()->last_login_at->diffForHumans() }} ·
                @endif
                {{ now()->format('l, F j, Y') }}
            </p>
        </div>
        <div class="text-xs text-slate-400 dark:text-slate-500">
            Welcome to <span class="font-semibold text-slate-600 dark:text-slate-300">SG NOC</span>
        </div>
    </div>

    {{-- ─── KPI Tiles (4-up) ─── --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @include('admin.welcome.kpi-tile', [
            'title' => 'Open Incidents',
            'value' => $kpis['incidents_open'],
            'icon'  => 'bi-exclamation-triangle',
            'tone'  => 'red',
            'href'  => Route::has('admin.noc.incidents.index') ? route('admin.noc.incidents.index') : null,
            'show'  => $kpis['incidents_open'] !== null,
        ])
        @include('admin.welcome.kpi-tile', [
            'title' => 'Open NOC Events',
            'value' => $kpis['noc_events_open'],
            'icon'  => 'bi-broadcast-pin',
            'tone'  => 'amber',
            'href'  => Route::has('admin.noc.events') ? route('admin.noc.events') : null,
            'show'  => $kpis['noc_events_open'] !== null,
        ])
        @include('admin.welcome.kpi-tile', [
            'title' => 'Pending Forms',
            'value' => $kpis['forms_pending'],
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
            'icon'     => 'bi-cloud-check',
            'tone'     => 'green',
            'href'     => Route::has('admin.identity.sync-logs') ? route('admin.identity.sync-logs') : null,
            'show'     => $kpis['identity_sync_health'] !== null,
        ])
    </div>

    {{-- ─── Two-column body ─── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        @if($activity->isNotEmpty())
            <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        Recent Activity
                    </h2>
                    @if(Route::has('admin.activity-logs'))
                        <a href="{{ route('admin.activity-logs') }}"
                           class="text-xs text-blue-600 dark:text-blue-400 hover:underline">View all →</a>
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
