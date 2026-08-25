@php
    /** @var array{total:int,critical:int,healthy:int,average:int,worst:\Illuminate\Support\Collection} $health */
    $total    = $health['total']    ?? 0;
    $critical = $health['critical'] ?? 0;
    $healthy  = $health['healthy']  ?? 0;
    $average  = $health['average']  ?? 0;
    $worst    = $health['worst']    ?? collect();

    // Fed by HealthScoringService, the same source as the NOC dashboard -- this
    // widget used to compute its own number from ISP link checks alone and could
    // therefore show all-green while the NOC showed the same branches degraded.
    $tone = match (true) {
        $total === 0   => 'green',
        $critical > 0  => 'red',
        $average >= 90 => 'green',
        $average >= 75 => 'amber',
        default        => 'red',
    };

    $toneMap = [
        'green' => ['gradFrom' => '#10b981', 'gradTo' => '#0d9488', 'badge' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300', 'dot' => 'bg-emerald-500'],
        'amber' => ['gradFrom' => '#f59e0b', 'gradTo' => '#ea580c', 'badge' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',   'dot' => 'bg-amber-500'],
        'red'   => ['gradFrom' => '#ef4444', 'gradTo' => '#e11d48', 'badge' => 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300',          'dot' => 'bg-red-500'],
    ];
    $t = $toneMap[$tone];

    $statusTone = [
        'excellent' => 'bg-emerald-500',
        'good'      => 'bg-emerald-500',
        'degraded'  => 'bg-amber-500',
        'critical'  => 'bg-red-500',
        'unknown'   => 'bg-slate-400',
    ];

    // SVG ring math: r=42, circumference ≈ 263.89
    $r = 42; $c = 2 * pi() * $r;
    $offset = $c - ($average / 100) * $c;
    $gradId = 'bh-grad-' . uniqid();
@endphp

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6 shadow-sm">
    <div class="flex items-center justify-between mb-1">
        <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">Branch Health</h2>
        <span class="inline-flex items-center gap-1.5 text-[11px] px-2 py-0.5 rounded-full {{ $t['badge'] }} font-semibold uppercase tracking-wider">
            <span class="w-1.5 h-1.5 rounded-full {{ $t['dot'] }}"></span>
            {{ $critical === 0 ? 'None critical' : $critical . ' critical' }}
        </span>
    </div>
    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">VoIP, network and device health across sites</p>

    <div class="flex items-center gap-5">
        <div class="relative shrink-0">
            <svg width="100" height="100" viewBox="0 0 100 100" class="-rotate-90">
                <defs>
                    <linearGradient id="{{ $gradId }}" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%"   stop-color="{{ $t['gradFrom'] }}"/>
                        <stop offset="100%" stop-color="{{ $t['gradTo'] }}"/>
                    </linearGradient>
                </defs>
                <circle cx="50" cy="50" r="{{ $r }}" stroke="currentColor" stroke-width="8"
                        fill="none" class="text-slate-100 dark:text-slate-700"/>
                <circle cx="50" cy="50" r="{{ $r }}" stroke="url(#{{ $gradId }})" stroke-width="8"
                        fill="none" stroke-linecap="round"
                        stroke-dasharray="{{ $c }}"
                        stroke-dashoffset="{{ $offset }}"
                        style="transition: stroke-dashoffset .6s ease;"/>
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <div class="text-xl font-bold text-slate-800 dark:text-slate-100 leading-none">{{ $average }}%</div>
                <div class="text-[10px] text-slate-400 uppercase tracking-wider mt-0.5">Avg</div>
            </div>
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex items-baseline gap-1.5">
                <div class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ $healthy }}</div>
                <div class="text-sm text-slate-500 dark:text-slate-400">/ {{ $total }}</div>
            </div>
            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">branches good or better</div>
        </div>
    </div>

    @if($worst->isNotEmpty())
        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-700">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-2">Needs attention</div>
            <ul class="space-y-1.5">
                @foreach($worst as $b)
                    <li class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $statusTone[$b['status']] ?? 'bg-slate-400' }}"></span>
                        <a href="{{ route('admin.noc.branch', $b['id']) }}" class="truncate hover:underline">{{ $b['name'] }}</a>
                        @if($b['capped'])
                            <span class="text-[10px] text-red-600 dark:text-red-400 shrink-0" title="Score capped by a critical condition">capped</span>
                        @endif
                        <span class="ml-auto text-xs font-semibold text-slate-500 dark:text-slate-400 shrink-0">{{ $b['total'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
