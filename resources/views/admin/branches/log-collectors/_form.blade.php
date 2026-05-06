@csrf

@if($errors->any())
    <div class="alert alert-danger py-2">
        <ul class="mb-0 small">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-md-3">
        <label class="form-label">Branch code <span class="text-danger">*</span></label>
        <input type="text" name="code"
               value="{{ old('code', $collector->code) }}"
               class="form-control"
               placeholder="jed, ryd, mak…"
               maxlength="8"
               {{ $collector->exists ? 'readonly' : 'required' }}>
        <small class="text-muted">
            2–8 lowercase letters/digits. Must match <code>BRANCH_ID</code> on the VM.
            @if($collector->exists)
                <em>(can't change after creation)</em>
            @endif
        </small>
    </div>

    <div class="col-md-5">
        <label class="form-label">Display name <span class="text-danger">*</span></label>
        <input type="text" name="name"
               value="{{ old('name', $collector->name) }}"
               class="form-control"
               placeholder="Jeddah office"
               required>
    </div>

    <div class="col-md-4">
        <label class="form-label">Status</label>
        <div class="form-check form-switch mt-2">
            <input type="hidden" name="enabled" value="0">
            <input class="form-check-input" type="checkbox" id="enabledSwitch"
                   name="enabled" value="1"
                   @if(old('enabled', $collector->enabled)) checked @endif>
            <label class="form-check-label" for="enabledSwitch">Enabled (queryable from NOC)</label>
        </div>
    </div>

    <div class="col-md-9">
        <label class="form-label">Host <span class="text-danger">*</span></label>
        <input type="text" name="host"
               value="{{ old('host', $collector->host) }}"
               class="form-control font-monospace"
               placeholder="10.1.0.5"
               required>
        <small class="text-muted">
            IPsec tunnel-side IP or hostname of the branch VM.
        </small>
    </div>

    <div class="col-md-3">
        <label class="form-label">Port <span class="text-danger">*</span></label>
        <input type="number" name="port"
               value="{{ old('port', $collector->port ?: 8514) }}"
               class="form-control"
               min="1" max="65535"
               required>
    </div>

    <div class="col-md-12">
        <label class="form-label">
            API token
            @if($collector->exists)
                <small class="text-muted">(leave blank to keep current)</small>
            @else
                <span class="text-danger">*</span>
            @endif
        </label>
        <div class="input-group">
            <input type="text" name="api_token"
                   value="{{ old('api_token') }}"
                   class="form-control font-monospace"
                   placeholder="64-char hex string from /etc/sg-noc-branch.env on the VM"
                   autocomplete="off"
                   {{ $collector->exists ? '' : 'required' }}>
            <button type="button" class="btn btn-outline-secondary" id="genTokenBtn"
                    data-url="{{ route('admin.branches.log-collectors.generate-token') }}">
                <i class="bi bi-shuffle"></i> Generate
            </button>
        </div>
        <small class="text-muted">
            On the branch VM this lives in <code>/etc/sg-noc-branch.env</code>
            as <code>API_TOKEN</code>. <strong>Both must match exactly</strong>
            for queries to authenticate. The "Generate" button gives you a
            random value — paste the same string into the VM's env file.
        </small>
    </div>

    <div class="col-md-12">
        <label class="form-label">Notes</label>
        <textarea name="notes" rows="2" class="form-control"
                  placeholder="e.g. installed by AzureUser on 2026-05-06; behind /29 subnet">{{ old('notes', $collector->notes) }}</textarea>
    </div>
</div>

<div class="mt-3 d-flex gap-2">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i>{{ $collector->exists ? 'Save changes' : 'Add branch' }}
    </button>
    <a href="{{ route('admin.branches.log-collectors.index') }}" class="btn btn-outline-secondary">Cancel</a>
</div>

<script>
(function () {
    const btn = document.getElementById('genTokenBtn');
    if (!btn) return;
    btn.addEventListener('click', async () => {
        try {
            const r = await fetch(btn.dataset.url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            });
            const data = await r.json();
            const input = document.querySelector('input[name="api_token"]');
            input.value = data.token;
            input.select();
            navigator.clipboard?.writeText(data.token);
            btn.innerHTML = '<i class="bi bi-clipboard-check"></i> Copied';
            setTimeout(() => { btn.innerHTML = '<i class="bi bi-shuffle"></i> Generate'; }, 1500);
        } catch (e) {
            alert('Failed to generate token');
        }
    });
})();
</script>
