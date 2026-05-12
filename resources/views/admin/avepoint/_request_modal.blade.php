{{-- Reusable "Request Backup" modal. Include once per page; trigger with --}}
{{--   <button data-bs-toggle="modal" data-bs-target="#avepointRequestModal" --}}
{{--           data-upn="..." data-name="...">Request</button>                  --}}
<div class="modal fade" id="avepointRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-cloud-arrow-down me-1"></i>Request AvePoint Backup</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="avepointReqResult"></div>
                <form id="avepointReqForm">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">User (UPN / email)</label>
                        <input type="email" name="upn" id="avepointReqUpn" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label d-block">What to back up</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="types[]" value="mailbox" id="avepointReqMail" checked>
                            <label class="form-check-label" for="avepointReqMail"><i class="bi bi-envelope me-1"></i>Mailbox</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="types[]" value="onedrive" id="avepointReqOd" checked>
                            <label class="form-check-label" for="avepointReqOd"><i class="bi bi-cloud me-1"></i>OneDrive</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes <small class="text-muted">(optional)</small></label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="500" placeholder="Why this backup is being requested…"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="avepointReqSubmit" class="btn btn-primary">
                    <i class="bi bi-play-fill me-1"></i>Request Backup
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const modalEl  = document.getElementById('avepointRequestModal');
    if (! modalEl) return;
    const upnInput = modalEl.querySelector('#avepointReqUpn');
    const submit   = modalEl.querySelector('#avepointReqSubmit');
    const form     = modalEl.querySelector('#avepointReqForm');
    const result   = modalEl.querySelector('#avepointReqResult');

    modalEl.addEventListener('show.bs.modal', e => {
        const trigger = e.relatedTarget;
        result.innerHTML = '';
        if (trigger && trigger.dataset.upn) {
            upnInput.value = trigger.dataset.upn;
            upnInput.readOnly = true;
        } else {
            upnInput.value = '';
            upnInput.readOnly = false;
        }
    });

    submit.addEventListener('click', () => {
        const fd = new FormData(form);
        if (! fd.get('upn')) {
            result.innerHTML = '<div class="alert alert-warning py-2 small">UPN is required.</div>';
            return;
        }
        if (! fd.getAll('types[]').length) {
            result.innerHTML = '<div class="alert alert-warning py-2 small">Pick at least one backup type.</div>';
            return;
        }

        submit.disabled = true;
        const orig = submit.innerHTML;
        submit.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Requesting…';

        fetch('{{ route('admin.avepoint.request') }}', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: fd,
        })
        .then(r => r.json().then(j => ({ status: r.status, body: j })))
        .then(({ status, body }) => {
            if (status >= 200 && status < 300 && body.ok) {
                const created = (body.created || []).map(c => c.type).join(', ') || '(none)';
                const skipped = (body.skipped || []).length;
                result.innerHTML = '<div class="alert alert-success py-2 small">'
                    + 'Dispatched: <strong>' + created + '</strong>'
                    + (skipped ? '. ' + skipped + ' already in flight.' : '.')
                    + ' Refresh the page in a few minutes to see progress.</div>';
            } else {
                result.innerHTML = '<div class="alert alert-danger py-2 small">' + (body.message || ('HTTP ' + status)) + '</div>';
            }
        })
        .catch(err => {
            result.innerHTML = '<div class="alert alert-danger py-2 small">' + err.message + '</div>';
        })
        .finally(() => {
            submit.disabled = false;
            submit.innerHTML = orig;
        });
    });
})();
</script>
@endpush
