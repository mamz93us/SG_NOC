@php
    $__flashes = [
        'success' => ['icon' => 'bi-check-circle-fill', 'classes' => 'bg-green-50 text-green-800 border-green-200 dark:bg-green-900/20 dark:text-green-200 dark:border-green-800'],
        'error'   => ['icon' => 'bi-x-octagon-fill',    'classes' => 'bg-red-50 text-red-800 border-red-200 dark:bg-red-900/20 dark:text-red-200 dark:border-red-800'],
        'info'    => ['icon' => 'bi-info-circle-fill',  'classes' => 'bg-blue-50 text-blue-800 border-blue-200 dark:bg-blue-900/20 dark:text-blue-200 dark:border-blue-800'],
        'warning' => ['icon' => 'bi-exclamation-triangle-fill', 'classes' => 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-900/20 dark:text-amber-200 dark:border-amber-800'],
    ];
@endphp

@foreach($__flashes as $key => $cfg)
    @if(session($key))
        <div x-data="{ show: true }" x-show="show" x-cloak
             class="mb-4 px-4 py-3 rounded-md border flex items-start gap-3 {{ $cfg['classes'] }}">
            <i class="bi {{ $cfg['icon'] }} text-lg mt-0.5"></i>
            <div class="flex-1 text-sm">{{ session($key) }}</div>
            <button type="button" @click="show = false" class="text-current opacity-50 hover:opacity-100">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    @endif
@endforeach
