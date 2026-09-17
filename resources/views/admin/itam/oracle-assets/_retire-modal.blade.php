{{--
    Retire an asset. One modal per page, filled from the button that opens it:
      data-bs-toggle="modal" data-bs-target="#retireAssetModal"
      data-action="(POST url)" data-asset="SG-LAP-000123 · name" data-holder="Employee name"
    The script is inline, not pushed: a pushed script from a nested partial can land after the page's own.
--}}
<div class="modal fade" id="retireAssetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" id="retireAssetForm">
            @csrf
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-archive me-2"></i>Retire asset</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2"><strong data-retire-asset></strong><span class="text-muted" data-retire-holder></span></p>
                <p class="small text-muted">
                    Out of service for good, written off without the scrap approval. The holder's assignment is closed
                    on the date below and the asset leaves their list. It is not removed from Intune.
                </p>
                <div class="mb-2">
                    <label class="form-label small fw-semibold" for="retireAssetOn">Retired on</label>
                    <input type="date" name="retired_on" id="retireAssetOn" class="form-control form-control-sm" style="max-width:180px"
                           value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required>
                </div>
                <div>
                    <label class="form-label small fw-semibold" for="retireAssetReason">Reason</label>
                    <textarea name="reason" id="retireAssetReason" class="form-control form-control-sm" rows="2" maxlength="1000" required
                              placeholder="e.g. 2013 laptop the employee no longer has; written off."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-secondary"><i class="bi bi-archive me-1"></i>Retire</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('retireAssetModal')?.addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    if (!btn) return;
    document.getElementById('retireAssetForm').action = btn.getAttribute('data-action');
    this.querySelector('[data-retire-asset]').textContent = btn.getAttribute('data-asset') || '';
    const holder = btn.getAttribute('data-holder');
    this.querySelector('[data-retire-holder]').textContent = holder ? ' — held by ' + holder : '';
});
</script>
