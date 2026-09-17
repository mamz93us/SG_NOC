{{--
    Link an asset to its Intune device (DeviceIntuneLinkController).
      data-bs-toggle="modal" data-bs-target="#intuneLinkModal"
      data-action="(POST url)" data-asset="SG-LAP-000123 · name"
      data-options='[{"id": 12, "label": "…"}]'   the holder's own Intune devices, listed first
    Needs $unlinkedIntune: Intune computers no asset is linked to (IntuneCandidates::unlinkedComputers()).
--}}
<div class="modal fade" id="intuneLinkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" class="modal-content" id="intuneLinkForm">
            @csrf
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-microsoft me-2"></i>Link to Intune device</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2"><strong data-link-asset></strong></p>
                <p class="small text-muted">
                    Every laptop and desktop has to be in Intune. Choose the Intune device this asset is. If that device
                    already has a NOC asset, the Oracle asset number moves onto it and the asset the import created is deleted.
                </p>
                <label class="form-label small fw-semibold" for="intuneLinkSelect">Intune device</label>
                <select name="azure_device_id" id="intuneLinkSelect" class="form-select form-select-sm" required>
                    <option value="">Choose…</option>
                    <optgroup label="This person's Intune devices" id="intuneLinkMine"></optgroup>
                    <optgroup label="Other Intune devices not linked to any asset" id="intuneLinkOthers">
                        @foreach ($unlinkedIntune as $intune)
                            <option value="{{ $intune->id }}">{{ collect([
                                $intune->display_name,
                                trim($intune->manufacturer.' '.$intune->model) ?: null,
                                $intune->serial_number ? 'SN '.$intune->serial_number : null,
                                $intune->upn,
                                $intune->enrolled_date ? 'enrolled '.$intune->enrolled_date->format('M Y') : null,
                            ])->filter()->implode(' · ') }}</option>
                        @endforeach
                    </optgroup>
                </select>
                <div class="form-text" id="intuneLinkNone">This person has no Intune device waiting for an Oracle asset; pick from the others, or retire the asset if it is gone.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-link-45deg me-1"></i>Link</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('intuneLinkModal')?.addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    if (!btn) return;
    document.getElementById('intuneLinkForm').action = btn.getAttribute('data-action');
    this.querySelector('[data-link-asset]').textContent = btn.getAttribute('data-asset') || '';

    let mine = [];
    try { mine = JSON.parse(btn.getAttribute('data-options') || '[]'); } catch (err) { mine = []; }

    const select = document.getElementById('intuneLinkSelect');
    const group = document.getElementById('intuneLinkMine');
    const ids = new Set(mine.map(o => String(o.id)));

    group.innerHTML = '';
    mine.forEach(function (o) {
        const option = document.createElement('option');
        option.value = o.id;
        option.textContent = o.label;
        group.appendChild(option);
    });
    group.hidden = mine.length === 0;
    document.querySelectorAll('#intuneLinkOthers option').forEach(function (option) {
        option.hidden = ids.has(option.value);
    });
    document.getElementById('intuneLinkNone').hidden = mine.length > 0;
    select.value = mine.length === 1 ? String(mine[0].id) : '';
});
</script>
