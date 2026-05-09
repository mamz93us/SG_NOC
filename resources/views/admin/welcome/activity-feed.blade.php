@php
    /** @var \Illuminate\Support\Collection $activity */
    $iconFor = function (?string $modelType) {
        $type = class_basename($modelType ?? 'System');
        return match (true) {
            str_contains($type, 'Contact')      => ['bi-person-lines-fill', 'from-blue-500 to-indigo-500'],
            str_contains($type, 'Branch')       => ['bi-diagram-3-fill',    'from-cyan-500 to-blue-500'],
            str_contains($type, 'Device')       => ['bi-cpu',               'from-violet-500 to-purple-500'],
            str_contains($type, 'Printer')      => ['bi-printer',           'from-fuchsia-500 to-pink-500'],
            str_contains($type, 'User')         => ['bi-person-badge',      'from-emerald-500 to-teal-500'],
            str_contains($type, 'Workflow')     => ['bi-diagram-2',         'from-indigo-500 to-purple-500'],
            str_contains($type, 'Form')         => ['bi-ui-checks',         'from-blue-500 to-cyan-500'],
            str_contains($type, 'Incident')     => ['bi-exclamation-triangle', 'from-red-500 to-rose-500'],
            str_contains($type, 'Noc')          => ['bi-broadcast-pin',     'from-amber-500 to-orange-500'],
            str_contains($type, 'Credential')   => ['bi-key-fill',          'from-yellow-500 to-amber-500'],
            str_contains($type, 'Setting')      => ['bi-gear',              'from-slate-500 to-slate-700'],
            str_contains($type, 'Network')      => ['bi-diagram-3',         'from-sky-500 to-blue-500'],
            str_contains($type, 'Sync')         => ['bi-arrow-repeat',      'from-teal-500 to-emerald-500'],
            default                             => ['bi-clock-history',     'from-slate-400 to-slate-600'],
        };
    };

    $userColor = function (?string $name) {
        $palette = ['from-rose-400 to-pink-500','from-amber-400 to-orange-500','from-emerald-400 to-teal-500','from-sky-400 to-blue-500','from-violet-400 to-purple-500','from-cyan-400 to-sky-500'];
        return $palette[abs(crc32($name ?? 'system')) % count($palette)];
    };
@endphp

<ul class="space-y-1 -mx-2">
    @foreach($activity as $log)
        @php
            [$mIcon, $mGrad] = $iconFor($log->model_type);
            $modelLabel = class_basename($log->model_type ?? 'System');
            $userName   = $log->user?->name ?? 'System';
            $userInit   = strtoupper(substr($userName, 0, 1));
            $uColor     = $userColor($userName);
        @endphp
        <li class="flex items-start gap-3 px-2 py-2.5 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
            <div class="relative shrink-0">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br {{ $uColor }} flex items-center justify-center text-white font-semibold text-xs shadow-sm">
                    {{ $userInit }}
                </div>
                <div class="absolute -bottom-1 -right-1 w-5 h-5 rounded-full bg-gradient-to-br {{ $mGrad }} flex items-center justify-center ring-2 ring-white dark:ring-slate-800">
                    <i class="bi {{ $mIcon }} text-[8px] text-white"></i>
                </div>
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-sm text-slate-700 dark:text-slate-200">
                    <span class="font-semibold">{{ $userName }}</span>
                    <span class="text-slate-500 dark:text-slate-400">{{ $log->action }}</span>
                </div>
                <div class="flex items-center gap-2 text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                    @if($modelLabel !== 'System')
                        <span class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 font-medium text-[10px] uppercase tracking-wider">
                            {{ $modelLabel }}@if($log->model_id) #{{ $log->model_id }}@endif
                        </span>
                        <span>·</span>
                    @endif
                    <span>{{ $log->created_at?->diffForHumans() ?? '' }}</span>
                </div>
            </div>
        </li>
    @endforeach
</ul>
