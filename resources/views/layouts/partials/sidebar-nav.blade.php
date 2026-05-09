@php
    $__nav = config('admin_navigation', []);
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
    @endphp

    @continue($visible->isEmpty())

    <div class="px-3 pt-4 pb-1">
        <span x-show="!sidebarCollapsed"
              class="text-[10px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
            {{ $group['label'] }}
        </span>
        <div x-show="sidebarCollapsed" class="border-t border-slate-200 dark:border-slate-700 mx-1 my-1"></div>
    </div>

    @foreach($visible as $item)
        @php
            $href   = route($item['route']);
            $active = request()->routeIs($item['route']) || request()->routeIs($item['route'] . '.*');
        @endphp
        <a href="{{ $href }}"
           title="{{ $item['label'] }}"
           class="group flex items-center gap-3 mx-2 px-3 py-2 rounded-md text-sm transition
                  {{ $active
                       ? 'bg-blue-50 text-blue-700 dark:bg-slate-700 dark:text-blue-300'
                       : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700' }}">
            <i class="bi {{ $item['icon'] ?? 'bi-arrow-right' }} text-base shrink-0
                      {{ $active ? '' : 'text-slate-400 dark:text-slate-500 group-hover:text-slate-600 dark:group-hover:text-slate-300' }}"></i>
            <span x-show="!sidebarCollapsed" class="truncate">{{ $item['label'] }}</span>
        </a>
    @endforeach
@endforeach
