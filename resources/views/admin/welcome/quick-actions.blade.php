@php
    /** @var array<int, array{label:string,url:string,icon:string,tone:string}> $actions */
    $toneMap = [
        'red'    => ['gradient' => 'from-red-500 to-rose-600',     'hover' => 'hover:border-red-200 dark:hover:border-red-700/50'],
        'amber'  => ['gradient' => 'from-amber-500 to-orange-600', 'hover' => 'hover:border-amber-200 dark:hover:border-amber-700/50'],
        'blue'   => ['gradient' => 'from-blue-500 to-indigo-600',  'hover' => 'hover:border-blue-200 dark:hover:border-blue-700/50'],
        'green'  => ['gradient' => 'from-emerald-500 to-teal-600', 'hover' => 'hover:border-emerald-200 dark:hover:border-emerald-700/50'],
        'indigo' => ['gradient' => 'from-indigo-500 to-purple-600','hover' => 'hover:border-indigo-200 dark:hover:border-indigo-700/50'],
        'slate'  => ['gradient' => 'from-slate-500 to-slate-700',  'hover' => 'hover:border-slate-300 dark:hover:border-slate-600'],
    ];
@endphp

@if(! empty($actions))
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6 shadow-sm">
        <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">Quick Actions</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Jump straight to what you need</p>

        <div class="grid grid-cols-2 gap-2">
            @foreach($actions as $a)
                @php $t = $toneMap[$a['tone']] ?? $toneMap['slate']; @endphp
                <a href="{{ $a['url'] }}"
                   class="group flex flex-col items-start gap-2 p-3 rounded-lg border border-slate-200 dark:border-slate-700 transition hover:shadow-md {{ $t['hover'] }}">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center bg-gradient-to-br {{ $t['gradient'] }} shadow-sm group-hover:scale-110 transition">
                        <i class="bi {{ $a['icon'] }} text-base text-white"></i>
                    </div>
                    <div class="text-xs font-medium text-slate-700 dark:text-slate-200 leading-tight">{{ $a['label'] }}</div>
                </a>
            @endforeach
        </div>
    </div>
@endif
