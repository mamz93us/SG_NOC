@php
    /** @var \Illuminate\Support\Collection $activity */
    $iconFor = function (?string $modelType) {
        $type = class_basename($modelType ?? 'System');
        return match (true) {
            str_contains($type, 'Contact')      => 'bi-person-lines-fill',
            str_contains($type, 'Branch')       => 'bi-diagram-3-fill',
            str_contains($type, 'Device')       => 'bi-cpu',
            str_contains($type, 'Printer')      => 'bi-printer',
            str_contains($type, 'User')         => 'bi-person-badge',
            str_contains($type, 'Workflow')     => 'bi-diagram-2',
            str_contains($type, 'Form')         => 'bi-ui-checks',
            str_contains($type, 'Incident')     => 'bi-exclamation-triangle',
            str_contains($type, 'Noc')          => 'bi-broadcast-pin',
            str_contains($type, 'Credential')   => 'bi-key-fill',
            str_contains($type, 'Setting')      => 'bi-gear',
            str_contains($type, 'Network')      => 'bi-diagram-3',
            default                             => 'bi-clock-history',
        };
    };
@endphp

<ul class="divide-y divide-slate-100 dark:divide-slate-700 -mx-2">
    @foreach($activity as $log)
        @php
            $modelLabel = class_basename($log->model_type ?? 'System');
            $userName   = $log->user?->name ?? 'System';
        @endphp
        <li class="flex items-start gap-3 px-2 py-2.5">
            <div class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-700 flex items-center justify-center shrink-0 mt-0.5">
                <i class="bi {{ $iconFor($log->model_type) }} text-slate-500 dark:text-slate-300 text-sm"></i>
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-sm text-slate-700 dark:text-slate-200 truncate">
                    <span class="font-medium">{{ $userName }}</span>
                    <span class="text-slate-500 dark:text-slate-400">{{ $log->action }}</span>
                    @if($modelLabel !== 'System')
                        <span class="text-slate-400 dark:text-slate-500">· {{ $modelLabel }}@if($log->model_id) #{{ $log->model_id }}@endif</span>
                    @endif
                </div>
                <div class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                    {{ $log->created_at?->diffForHumans() ?? '' }}
                </div>
            </div>
        </li>
    @endforeach
</ul>
