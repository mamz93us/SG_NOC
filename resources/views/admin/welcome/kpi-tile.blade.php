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
        'red'    => ['gradient' => 'from-red-500 to-rose-600',       'glow' => 'bg-red-500/10',    'text' => 'text-red-600 dark:text-red-300'],
        'amber'  => ['gradient' => 'from-amber-500 to-orange-600',   'glow' => 'bg-amber-500/10',  'text' => 'text-amber-600 dark:text-amber-300'],
        'blue'   => ['gradient' => 'from-blue-500 to-indigo-600',    'glow' => 'bg-blue-500/10',   'text' => 'text-blue-600 dark:text-blue-300'],
        'green'  => ['gradient' => 'from-emerald-500 to-teal-600',   'glow' => 'bg-emerald-500/10','text' => 'text-emerald-600 dark:text-emerald-300'],
        'indigo' => ['gradient' => 'from-indigo-500 to-purple-600',  'glow' => 'bg-indigo-500/10', 'text' => 'text-indigo-600 dark:text-indigo-300'],
        'slate'  => ['gradient' => 'from-slate-500 to-slate-700',    'glow' => 'bg-slate-500/10',  'text' => 'text-slate-600 dark:text-slate-300'],
    ];
    $t = $tones[$tone ?? 'slate'] ?? $tones['slate'];
    $tag = $href ? 'a' : 'div';
@endphp

@if($show)
    <{{ $tag }}
        @if($href) href="{{ $href }}" @endif
        class="group relative overflow-hidden bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm transition-all hover:shadow-lg @if($href) hover:-translate-y-0.5 hover:border-slate-300 dark:hover:border-slate-600 cursor-pointer @endif">

        {{-- decorative glow blob --}}
        <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full {{ $t['glow'] }} blur-2xl"></div>

        <div class="relative flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-gradient-to-br {{ $t['gradient'] }} shadow-md shrink-0">
                <i class="bi {{ $icon }} text-2xl text-white"></i>
            </div>

            <div class="min-w-0 flex-1">
                <div class="text-3xl font-bold text-slate-800 dark:text-slate-100 leading-none tracking-tight">
                    {{ $value ?? '—' }}
                </div>
                <div class="text-sm font-medium text-slate-700 dark:text-slate-200 mt-1.5 truncate">
                    {{ $title }}
                </div>
                @if($subtitle)
                    <div class="text-xs {{ $t['text'] }} mt-1 truncate font-medium">{{ $subtitle }}</div>
                @endif
            </div>

            @if($href)
                <i class="bi bi-arrow-up-right text-slate-300 dark:text-slate-600 group-hover:{{ $t['text'] }} transition shrink-0"></i>
            @endif
        </div>

    </{{ $tag }}>
@endif
