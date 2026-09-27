{{--
    Hand one asset from the employee holding it to another (AssetTransferController::transferDevice).
      data-bs-toggle="modal" data-bs-target="#transferAssetModal"
      data-action="(POST url)" data-asset="SG-LAP-000123 · name" data-holder="Employee name"
    Needs $transferEmployees: the employees an asset can go to.
--}}
<div class="modal fade" id="transferAssetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" id="transferAssetForm">
            @csrf
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>Transfer asset</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2"><strong data-transfer-asset></strong><span class="text-muted" data-transfer-holder></span></p>
                <p class="small text-muted">
                    Closes the current holder's assignment on the date below and opens one for the new holder. A handover
                    form to sign opens afterwards, and the movement is on the Asset Movements report for finance.
                </p>
                <div class="mb-2">
                    <label class="form-label small fw-semibold" for="transferAssetTo">New holder</label>
                    <input type="search" class="form-control form-control-sm mb-1" id="transferAssetFilter" placeholder="Filter by name or Oracle no." autocomplete="off">
                    <select name="to_employee_id" id="transferAssetTo" class="form-select form-select-sm" size="8" required>
                        @foreach ($transferEmployees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->name }}{{ $employee->oracle_emp_no ? ' — Emp #'.$employee->oracle_emp_no : '' }}{{ $employee->branch?->name ? ' · '.$employee->branch->name : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small fw-semibold" for="transferAssetDate">Handed over on</label>
                        <input type="date" name="transfer_date" id="transferAssetDate" class="form-control form-control-sm"
                               value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold" for="transferAssetCondition">Condition</label>
                        <select name="condition" id="transferAssetCondition" class="form-select form-select-sm">
                            <option value="good">Good</option>
                            <option value="fair">Fair</option>
                            <option value="poor">Poor</option>
                        </select>
                    </div>
                </div>
                <div class="mt-2">
                    <label class="form-label small fw-semibold" for="transferAssetNotes">Note <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea name="notes" id="transferAssetNotes" class="form-control form-control-sm" rows="2" maxlength="1000"
                              placeholder="e.g. Handed over when Ahmed moved to the Riyadh office."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-left-right me-1"></i>Transfer</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    const modal = document.getElementById('transferAssetModal');
    if (!modal) return;
    const select = document.getElementById('transferAssetTo');
    const filter = document.getElementById('transferAssetFilter');
    let holderId = null;

    function apply() {
        const needle = filter.value.trim().toLowerCase();
        select.querySelectorAll('option').forEach(function (option) {
            // The current holder is never a choice, whatever the filter says.
            option.hidden = (holderId !== null && option.value === holderId)
                || (needle !== '' && !option.textContent.toLowerCase().includes(needle));
        });
    }

    modal.addEventListener('show.bs.modal', function (e) {
        const btn = e.relatedTarget;
        if (!btn) return;
        document.getElementById('transferAssetForm').action = btn.getAttribute('data-action');
        modal.querySelector('[data-transfer-asset]').textContent = btn.getAttribute('data-asset') || '';
        const holder = btn.getAttribute('data-holder');
        modal.querySelector('[data-transfer-holder]').textContent = holder ? ' — held by ' + holder : '';
        holderId = btn.getAttribute('data-holder-id');
        filter.value = '';
        select.value = '';
        apply();
    });

    filter.addEventListener('input', apply);
})();
</script>
