{{--
    Employee search picker (multi-instance).

    Renders a type-ahead that queries portal.hr.employees.search and writes the
    chosen employee into hidden inputs. Consumers pass:

      $pickerId     — unique per instance on a page (default 'emp'). Every element
                      id and the change event are namespaced with it, so several
                      pickers can coexist.
      $selected     — an Employee model or null (pre-selected via ?employee=)
      $hiddenFields — map of input name => employee JSON key to populate,
                      e.g. ['manager_id' => 'id', 'manager_email' => 'email']
      $label        — field label (default 'Employee')
      $required     — show the red asterisk (default true)
      $help         — helper text under the field

    On selection it dispatches `hr:employee-selected` on document with
    detail = { picker: <pickerId>, employee: {...} }; on clear,
    `hr:employee-cleared` with detail = { picker: <pickerId> }.
--}}
@php
    $pickerId     = $pickerId     ?? 'emp';
    $hiddenFields = $hiddenFields ?? ['employee_id' => 'id'];
    $label        = $label        ?? 'Employee';
    $required     = $required     ?? true;
    $help         = $help         ?? 'Start typing to search active employees.';

    // Map a JSON key back to the model attribute, for pre-filling on old() /
    // ?employee= loads. Only the keys we can resolve server-side are listed.
    $prefill = function ($jsonKey) use ($selected) {
        if (! $selected) {
            return null;
        }

        return match ($jsonKey) {
            'email'     => $selected->email,
            'name'      => $selected->name,
            'job_title' => $selected->job_title,
            default     => $selected->id,
        };
    };
@endphp

<div class="mb-3" id="{{ $pickerId }}Wrap">
    <label class="form-label small fw-semibold">
        {{ $label }} @if($required)<span class="text-danger">*</span>@endif
    </label>

    @foreach($hiddenFields as $inputName => $jsonKey)
        <input type="hidden" name="{{ $inputName }}" id="{{ $pickerId }}Field_{{ $inputName }}"
               data-json-key="{{ $jsonKey }}"
               value="{{ old($inputName, $prefill($jsonKey)) }}">
    @endforeach

    <div class="position-relative">
        <input type="text" class="form-control form-control-sm" id="{{ $pickerId }}Search" autocomplete="off"
               placeholder="Search by name, work email or employee number…"
               value="{{ $selected?->name }}">
        <div id="{{ $pickerId }}Results" class="list-group position-absolute w-100 shadow"
             style="z-index:1050; max-height:280px; overflow-y:auto; display:none;"></div>
    </div>

    <div id="{{ $pickerId }}Chosen" class="mt-2 {{ $selected ? '' : 'd-none' }}">
        <div class="alert alert-light border d-flex justify-content-between align-items-center mb-0 py-2">
            <div class="small">
                <span class="fw-semibold" id="{{ $pickerId }}ChosenName">{{ $selected?->name }}</span>
                <span class="text-muted" id="{{ $pickerId }}ChosenMeta">
                    @if($selected)
                        — {{ $selected->email }}{{ $selected->job_title ? ' · '.$selected->job_title : '' }}{{ $selected->branch?->name ? ' · '.$selected->branch->name : '' }}
                    @endif
                </span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="{{ $pickerId }}Clear">Change</button>
        </div>
    </div>

    <div class="form-text">{{ $help }}</div>
</div>

@once
@push('scripts')
<script>
// Shared picker behaviour. initEmployeePicker() is called once per instance
// below, so several pickers can live on the same page without clashing ids.
window.initEmployeePicker = function (pickerId, searchUrl) {
    const wrap     = document.getElementById(pickerId + 'Wrap');
    const input    = document.getElementById(pickerId + 'Search');
    const results  = document.getElementById(pickerId + 'Results');
    const chosen   = document.getElementById(pickerId + 'Chosen');
    const chosenNm = document.getElementById(pickerId + 'ChosenName');
    const chosenMt = document.getElementById(pickerId + 'ChosenMeta');
    const clearBtn = document.getElementById(pickerId + 'Clear');
    const fields   = Array.from(document.querySelectorAll('[id^="' + pickerId + 'Field_"]'));

    if (!wrap || !input) return;

    let timer = null;
    let controller = null;

    function hide() { results.style.display = 'none'; results.innerHTML = ''; }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function select(emp) {
        fields.forEach(f => { f.value = emp[f.dataset.jsonKey] ?? ''; });
        chosenNm.textContent = emp.name;
        chosenMt.textContent = '— ' + [emp.email, emp.job_title, emp.branch].filter(Boolean).join(' · ');
        chosen.classList.remove('d-none');
        input.value = emp.name;
        hide();
        document.dispatchEvent(new CustomEvent('hr:employee-selected', {
            detail: { picker: pickerId, employee: emp }
        }));
    }

    input.addEventListener('input', function () {
        const q = input.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { hide(); return; }

        timer = setTimeout(async () => {
            if (controller) controller.abort();
            controller = new AbortController();
            try {
                const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' },
                    signal: controller.signal,
                });
                if (!res.ok) return hide();
                const list = await res.json();
                if (!list.length) {
                    results.innerHTML = '<div class="list-group-item text-muted small">No matching employee</div>';
                    results.style.display = 'block';
                    return;
                }
                results.innerHTML = '';
                list.forEach(emp => {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'list-group-item list-group-item-action';
                    item.innerHTML =
                        '<div class="fw-semibold">' + escapeHtml(emp.name) + '</div>' +
                        '<div class="small text-muted">' +
                        [emp.email, emp.job_title, emp.branch, emp.emp_no ? '#' + emp.emp_no : null]
                            .filter(Boolean).map(escapeHtml).join(' · ') +
                        '</div>';
                    item.addEventListener('click', () => select(emp));
                    results.appendChild(item);
                });
                results.style.display = 'block';
            } catch (e) {
                if (e.name !== 'AbortError') hide();
            }
        }, 250);
    });

    clearBtn?.addEventListener('click', function () {
        fields.forEach(f => { f.value = ''; });
        chosen.classList.add('d-none');
        input.value = '';
        input.focus();
        document.dispatchEvent(new CustomEvent('hr:employee-cleared', {
            detail: { picker: pickerId }
        }));
    });

    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) hide();
    });
};
</script>
@endpush
@endonce

@push('scripts')
<script>
initEmployeePicker(@json($pickerId), @json(route('portal.hr.employees.search')));
</script>
@endpush
