@php
    /** @var array{total:int,down:int,healthy:int,down_branches:\Illuminate\Support\Collection} $health */
    $total   = $health['total']   ?? 0;
    $down    = $health['down']    ?? 0;
    $healthy = $health['healthy'] ?? 0;
    $downList = $health['down_branches'] ?? collect();
    $pct     = $total > 0 ? (int) round($healthy / $total * 100) : 100;

    $tone = $down === 0 ? 'green' : ($down < max(1, (int) ceil($total * 0.2)) ? 'amber' : 'red');
    $toneMap = [
        'green' => ['dot' => 'bg-green-500', 'bar' => 'bg-green-500', 'text' => 'text-green-600 dark:text-green-400'],
        'amber' => ['dot' => 'bg-amber-500', 'bar' => 'bg-amber-500', 'text' => 'text-amber-600 dark:text-amber-400'],
        'red'   => ['dot' => 'bg-red-500',   'bar' => 'bg-red-500',   'text' => 'text-red-600 dark:text-red-400'],
    ];
    $t = $toneMap[$tone];
@endphp

<div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-5">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Branch Health
        </h2>
        <span class="flex items-center gap-1.5 text-xs {{ $t['text'] }}">
            <span class="w-2 h-2 rounded-full {{ $t['dot'] }}"></span>
            {{ $down === 0 ? 'All up' : $down . ' down' }}
        </span>
    </div>

    <div class="flex items-baseline gap-2">
        <div class="text-3xl font-semibold text-slate-800 dark:text-slate-100">{{ $healthy }}</div>
        <div class="text-sm text-slate-500 dark:text-slate-400">/ {{ $total }} healthy</div>
    </div>

    <div class="mt-3 h-1.5 bg-slate-100 dark:bg-slate-700 rounded overflow-hidden">
        <div class="h-full {{ $t['bar'] }}" style="width: {{ $pct }}%"></div>
    </div>

    @if($downList->isNotEmpty())
        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-700">
            <div class="text-xs font-semibold uppercase text-slate-400 dark:text-slate-500 mb-2">Down branches</div>
            <ul class="space-y-1">
                @foreach($downList as $b)
                    <li class="text-sm text-slate-600 dark:text-slate-300 truncate">
                        <i class="bi bi-circle-fill text-red-500 text-[6px] align-middle mr-2"></i>{{ $b->name }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
