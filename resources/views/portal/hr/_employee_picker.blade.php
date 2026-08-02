{{--
    Employee search picker.

    Renders a type-ahead that queries portal.hr.employees.search and writes the
    chosen employee into hidden inputs. Consumers pass:
      $selected  — an Employee model or null (pre-selected via ?employee=)
      $hiddenFields — map of input name => employee JSON key to populate
                      e.g. ['employee_id' => 'id', 'upn' => 'email']

    The chosen id is always written to the `employee_id` input.
--}}
@php
    $hiddenFields = $hiddenFields ?? ['employee_id' => 'id'];
@endphp

<div class="mb-3" id="empPickerWrap">
    <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>

    @foreach($hiddenFields as $inputName => $jsonKey)
        <input type="hidden" name="{{ $inputName }}" id="empField_{{ $inputName }}"
               data-json-key="{{ $jsonKey }}"
               value="{{ old($inputName, $selected?->{$jsonKey === 'email' ? 'email' : 'id'}) }}">
    @endforeach

    <div class="position-relative">
        <input type="text" class="form-control" id="empSearch" autocomplete="off"
               placeholder="Search by name, work email or employee number…"
               value="{{ $selected?->name }}">
        <div id="empResults" class="list-group position-absolute w-100 shadow"
             style="z-index:1050; max-height:280px; overflow-y:auto; display:none;"></div>
    </div>

    <div id="empChosen" class="mt-2 {{ $selected ? '' : 'd-none' }}">
        <div class="alert alert-light border d-flex justify-content-between align-items-center mb-0 py-2">
            <div class="small">
                <span class="fw-semibold" id="empChosenName">{{ $selected?->name }}</span>
                <span class="text-muted" id="empChosenMeta">
                    @if($selected)
                        — {{ $selected->email }}{{ $selected->job_title ? ' · '.$selected->job_title : '' }}{{ $selected->branch?->name ? ' · '.$selected->branch->name : '' }}
                    @endif
                </span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="empClear">Change</button>
        </div>
    </div>

    <div class="form-text">Start typing to search active employees.</div>
</div>

@push('scripts')
<script>
(function () {
    const searchUrl = @json(route('portal.hr.employees.search'));
    const input     = document.getElementById('empSearch');
    const results   = document.getElementById('empResults');
    const chosen    = document.getElementById('empChosen');
    const chosenNm  = document.getElementById('empChosenName');
    const chosenMt  = document.getElementById('empChosenMeta');
    const clearBtn  = document.getElementById('empClear');
    const fields    = Array.from(document.querySelectorAll('[id^="empField_"]'));

    let timer = null;
    let controller = null;

    function hide() { results.style.display = 'none'; results.innerHTML = ''; }

    function select(emp) {
        fields.forEach(f => { f.value = emp[f.dataset.jsonKey] ?? ''; });
        chosenNm.textContent = emp.name;
        chosenMt.textContent = '— ' + [emp.email, emp.job_title, emp.branch].filter(Boolean).join(' · ');
        chosen.classList.remove('d-none');
        input.value = emp.name;
        hide();
        // Let the page react (e.g. the update form reloads current values).
        document.dispatchEvent(new CustomEvent('hr:employee-selected', { detail: emp }));
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
        document.dispatchEvent(new CustomEvent('hr:employee-cleared'));
    });

    document.addEventListener('click', function (e) {
        if (!document.getElementById('empPickerWrap').contains(e.target)) hide();
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }
})();
</script>
@endpush
