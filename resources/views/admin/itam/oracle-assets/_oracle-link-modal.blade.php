{{--
    Link an asset with no Oracle number to its Oracle unit (DeviceOracleLinkController).
      data-bs-toggle="modal" data-bs-target="#oracleLinkModal"
      data-action="(POST url)" data-options-url="(GET url, JSON groups)" data-asset="SG-LAP-000123 · name"
    The choices are fetched when the dialog opens. The script is inline, not pushed.
--}}
<div class="modal fade" id="oracleLinkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" class="modal-content" id="oracleLinkForm">
            @csrf
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-journal-check me-2"></i>Link to an Oracle asset</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2"><strong data-oracle-link-asset></strong></p>
                <p class="small text-muted">
                    Choose the unit in Oracle's asset register this device is. It keeps its asset code and its holder, and takes
                    Oracle's asset number and purchase date. If the import had made a separate "Not in Intune" asset for that
                    unit, that asset is deleted. Units that look like a different model are marked but can still be chosen.
                </p>
                <input type="search" id="oracleLinkFilter" class="form-control form-control-sm mb-2"
                       placeholder="Filter by Oracle asset no., description or name" autocomplete="off">
                <select name="oracle_asset_id" id="oracleLinkSelect" class="form-select form-select-sm" size="12" required></select>
                <div class="form-text" id="oracleLinkStatus"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-link-45deg me-1"></i>Link</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    const modal = document.getElementById('oracleLinkModal');
    if (!modal) return;
    const select = document.getElementById('oracleLinkSelect');
    const filter = document.getElementById('oracleLinkFilter');
    const status = document.getElementById('oracleLinkStatus');

    modal.addEventListener('show.bs.modal', function (e) {
        const btn = e.relatedTarget;
        if (!btn) return;
        document.getElementById('oracleLinkForm').action = btn.getAttribute('data-action');
        modal.querySelector('[data-oracle-link-asset]').textContent = btn.getAttribute('data-asset') || '';
        select.innerHTML = '';
        filter.value = '';
        status.textContent = 'Loading the Oracle assets waiting for a device…';

        fetch(btn.getAttribute('data-options-url'), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
            .then(function (data) {
                let total = 0;
                (data.groups || []).forEach(function (group) {
                    if (!group.options || group.options.length === 0) return;
                    const optgroup = document.createElement('optgroup');
                    optgroup.label = group.label + ' (' + group.options.length + ')';
                    group.options.forEach(function (o) {
                        const option = document.createElement('option');
                        option.value = o.id;
                        option.textContent = o.label;
                        optgroup.appendChild(option);
                        total++;
                    });
                    select.appendChild(optgroup);
                });
                status.textContent = total === 0 ? 'No Oracle asset is waiting for a device.' : '';
            })
            .catch(function () {
                status.textContent = 'The Oracle assets could not be loaded. Reload the page and try again.';
            });
    });

    filter.addEventListener('input', function () {
        const needle = filter.value.trim().toLowerCase();
        select.querySelectorAll('optgroup').forEach(function (group) {
            let shown = 0;
            group.querySelectorAll('option').forEach(function (option) {
                option.hidden = needle !== '' && !option.textContent.toLowerCase().includes(needle);
                if (!option.hidden) shown++;
            });
            group.hidden = shown === 0;
        });
    });
})();
</script>
