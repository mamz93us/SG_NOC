@php
    $__nav = config('admin_navigation', []);

    $groupIcons = [
        'Welcome'   => 'bi-house-door',
        'VoIP'      => 'bi-telephone-fill',
        'Network'   => 'bi-diagram-3-fill',
        'Assets'    => 'bi-cpu-fill',
        'Monitor'   => 'bi-speedometer2',
        'Identity'  => 'bi-people-fill',
        'Workflows' => 'bi-diagram-2-fill',
        'Forms'     => 'bi-ui-checks',
        'Tools'     => 'bi-tools',
        'Logs'      => 'bi-journal-text',
        'Admin'     => 'bi-shield-lock-fill',
    ];
@endphp

@foreach($__nav as $group)
    @php
        $visible = collect($group['items'] ?? [])
            ->filter(function ($i) {
                if (! \Illuminate\Support\Facades\Route::has($i['route'])) return false;
                $perm = $i['permission'] ?? null;
                return $perm === null || (auth()->user()?->can($perm) ?? false);
            })
            ->values();
        $gIcon = $groupIcons[$group['label']] ?? 'bi-folder';
    @endphp

    @continue($visible->isEmpty())

    <div class="px-3 pt-5 pb-1 first:pt-2">
        <div x-show="!sidebarCollapsed"
             class="flex items-center gap-2 px-1">
            <i class="bi {{ $gIcon }} text-[11px] text-slate-400 dark:text-slate-500"></i>
            <span class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400 dark:text-slate-500">
                {{ $group['label'] }}
            </span>
        </div>
        <div x-show="sidebarCollapsed" class="flex items-center justify-center pt-1">
            <i class="bi {{ $gIcon }} text-sm text-slate-400 dark:text-slate-500" title="{{ $group['label'] }}"></i>
        </div>
    </div>

    @foreach($visible as $item)
        @php
            $href   = route($item['route']);
            $active = request()->routeIs($item['route']) || request()->routeIs($item['route'] . '.*');
        @endphp
        <a href="{{ $href }}"
           title="{{ $item['label'] }}"
           class="group relative flex items-center gap-3 mx-2 mt-0.5 px-3 py-2 rounded-lg text-sm font-medium transition
                  {{ $active
                       ? 'bg-gradient-to-r from-blue-50 to-indigo-50 text-blue-700 dark:from-blue-900/40 dark:to-indigo-900/30 dark:text-blue-200 shadow-sm'
                       : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700/60 hover:text-slate-900 dark:hover:text-white' }}">
            @if($active)
                <span class="absolute left-0 top-1.5 bottom-1.5 w-1 rounded-r-full bg-gradient-to-b from-blue-500 to-indigo-600"></span>
            @endif
            <i class="bi {{ $item['icon'] ?? 'bi-arrow-right' }} text-[15px] shrink-0
                      {{ $active
                           ? 'text-blue-600 dark:text-blue-300'
                           : 'text-slate-400 dark:text-slate-500 group-hover:text-slate-600 dark:group-hover:text-slate-300' }}"></i>
            <span x-show="!sidebarCollapsed" class="truncate">{{ $item['label'] }}</span>
        </a>
    @endforeach
@endforeach
