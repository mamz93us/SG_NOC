@php
    /** @var array<int, array{label:string,url:string,icon:string,tone:string}> $actions */
    $toneMap = [
        'red'    => 'text-red-600    dark:text-red-300',
        'amber'  => 'text-amber-600  dark:text-amber-300',
        'blue'   => 'text-blue-600   dark:text-blue-300',
        'green'  => 'text-green-600  dark:text-green-300',
        'indigo' => 'text-indigo-600 dark:text-indigo-300',
        'slate'  => 'text-slate-600  dark:text-slate-300',
    ];
@endphp

@if(! empty($actions))
    <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-3">
            Quick Actions
        </h2>
        <ul class="space-y-1">
            @foreach($actions as $a)
                <li>
                    <a href="{{ $a['url'] }}"
                       class="flex items-center gap-3 px-2 py-2 -mx-2 rounded-md text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700">
                        <i class="bi {{ $a['icon'] }} text-base {{ $toneMap[$a['tone']] ?? $toneMap['slate'] }}"></i>
                        <span>{{ $a['label'] }}</span>
                        <i class="bi bi-arrow-right ml-auto text-slate-300 dark:text-slate-500 text-xs"></i>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
