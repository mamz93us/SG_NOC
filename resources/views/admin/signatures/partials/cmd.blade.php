@php
    // $cmd carries the literal __KEY__ placeholder; the page JS swaps __KEY__ for the
    // pasted API key. Keep both forms: data-tpl for the JS, initial text for no-JS view.
    // (Do NOT use a {{ }}-style token here — Blade compiles it as an echo and errors.)
    $tpl = $cmd;
    $initial = str_replace('__KEY__', '<PASTE-API-KEY>', $cmd);
@endphp
<div class="position-relative" data-cmd id="{{ $id }}" data-tpl="{{ $tpl }}">
    <button type="button" class="btn btn-sm btn-outline-secondary position-absolute top-0 end-0 m-2"
            data-copy="{{ $id }}" style="z-index:2;">
        <i class="bi bi-clipboard me-1"></i>Copy
    </button>
    <pre class="bg-dark text-light rounded p-3 mb-0" style="overflow-x:auto;"><code style="white-space:pre-wrap;word-break:break-all;font-size:12.5px;">{{ $initial }}</code></pre>
</div>
