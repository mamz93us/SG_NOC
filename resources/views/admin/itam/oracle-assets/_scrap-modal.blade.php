{{--
    Request scrap for one asset, through the existing approval workflow (AssetScrapController::store).
      data-bs-toggle="modal" data-bs-target="#scrapAssetModal"
      data-device="(device id)" data-asset="SG-LAP-000123 · name" data-holder="Employee name"
--}}
<div class="modal fade" id="scrapAssetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('admin.itam.scrap.store') }}" class="modal-content" id="scrapAssetForm">
            @csrf
            <input type="hidden" name="device_ids[]" id="scrapAssetDevice">
            <input type="hidden" name="back" value="1">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-trash3 me-2"></i>Request scrap</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2"><strong data-scrap-asset></strong><span class="text-muted" data-scrap-holder></span></p>
                <p class="small text-muted">
                    Opens a scrap request for approval by the IT manager, then a super admin. The asset stays with its holder
                    until the request is approved; approval closes the assignment and marks it scrapped.
                </p>
                <div class="mb-2">
                    <label class="form-label small fw-semibold" for="scrapAssetReasonCode">Reason</label>
                    <select name="reason_code" id="scrapAssetReasonCode" class="form-select form-select-sm" required>
                        <option value="">Choose a reason…</option>
                        @foreach (\App\Services\Itam\AssetReasons::SCRAP as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold" for="scrapAssetMethod">Disposal</label>
                    <select name="disposal_method" id="scrapAssetMethod" class="form-select form-select-sm" style="max-width:240px" required>
                        <option value="destroy">Destroy</option>
                        <option value="recycle">Recycle</option>
                        <option value="donate">Donate</option>
                        <option value="sell">Sell</option>
                        <option value="return_to_supplier">Return to supplier</option>
                    </select>
                </div>
                <div>
                    <label class="form-label small fw-semibold" for="scrapAssetReason">Detail <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea name="reason" id="scrapAssetReason" class="form-control form-control-sm" rows="2" maxlength="2000"
                              placeholder="e.g. Broken screen and motherboard; quote was more than a new one."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash3 me-1"></i>Request scrap</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('scrapAssetModal')?.addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    if (!btn) return;
    document.getElementById('scrapAssetDevice').value = btn.getAttribute('data-device') || '';
    this.querySelector('[data-scrap-asset]').textContent = btn.getAttribute('data-asset') || '';
    const holder = btn.getAttribute('data-holder');
    this.querySelector('[data-scrap-holder]').textContent = holder ? ' — held by ' + holder : '';
});
</script>
