@php
    /**
     * @var string|null $title
     * @var mixed       $value
     * @var string      $icon
     * @var string      $tone   one of: red|amber|blue|green|indigo|slate
     * @var string|null $href
     * @var string|null $subtitle
     * @var bool        $show
     */
    $show     = $show     ?? true;
    $subtitle = $subtitle ?? null;

    $tones = [
        'red'    => ['ring' => 'ring-red-100',    'bg' => 'bg-red-50',    'icon' => 'text-red-600',    'darkBg' => 'dark:bg-red-900/20',    'darkIcon' => 'dark:text-red-300'],
        'amber'  => ['ring' => 'ring-amber-100',  'bg' => 'bg-amber-50',  'icon' => 'text-amber-600',  'darkBg' => 'dark:bg-amber-900/20',  'darkIcon' => 'dark:text-amber-300'],
        'blue'   => ['ring' => 'ring-blue-100',   'bg' => 'bg-blue-50',   'icon' => 'text-blue-600',   'darkBg' => 'dark:bg-blue-900/20',   'darkIcon' => 'dark:text-blue-300'],
        'green'  => ['ring' => 'ring-green-100',  'bg' => 'bg-green-50',  'icon' => 'text-green-600',  'darkBg' => 'dark:bg-green-900/20',  'darkIcon' => 'dark:text-green-300'],
        'indigo' => ['ring' => 'ring-indigo-100', 'bg' => 'bg-indigo-50', 'icon' => 'text-indigo-600', 'darkBg' => 'dark:bg-indigo-900/20', 'darkIcon' => 'dark:text-indigo-300'],
        'slate'  => ['ring' => 'ring-slate-100',  'bg' => 'bg-slate-50',  'icon' => 'text-slate-600',  'darkBg' => 'dark:bg-slate-700/40',  'darkIcon' => 'dark:text-slate-300'],
    ];
    $t = $tones[$tone ?? 'slate'] ?? $tones['slate'];
    $tag = $href ? 'a' : 'div';
@endphp

@if($show)
    <{{ $tag }}
        @if($href) href="{{ $href }}" @endif
        class="group bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-5 flex items-center gap-4 transition hover:shadow-md @if($href) hover:border-slate-300 dark:hover:border-slate-600 @endif">

        <div class="w-12 h-12 rounded-lg flex items-center justify-center {{ $t['bg'] }} {{ $t['darkBg'] }} ring-1 {{ $t['ring'] }} dark:ring-0 shrink-0">
            <i class="bi {{ $icon }} text-2xl {{ $t['icon'] }} {{ $t['darkIcon'] }}"></i>
        </div>

        <div class="min-w-0">
            <div class="text-2xl font-semibold text-slate-800 dark:text-slate-100 leading-tight">
                {{ $value ?? '—' }}
            </div>
            <div class="text-sm text-slate-500 dark:text-slate-400 mt-0.5 truncate">
                {{ $title }}
            </div>
            @if($subtitle)
                <div class="text-xs text-slate-400 dark:text-slate-500 mt-0.5 truncate">{{ $subtitle }}</div>
            @endif
        </div>

    </{{ $tag }}>
@endif
