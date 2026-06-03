{{-- ── SNMP Printer Auto-Discovery Modal ──────────────────────────────
     Scans an IP range over SNMP and auto-creates + polls every printer it
     finds that isn't already in the system. Requires $branches in scope. --}}
<div class="modal fade" id="discoverPrintersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.printers.discover-scan') }}" id="discoverPrintersForm">
                @csrf
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-semibold">
                        <i class="bi bi-broadcast-pin me-1 text-success"></i>Auto-Discover Printers (SNMP)
                    </h6>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Pings the range, queries each live host over SNMP, and automatically adds &amp;
                        polls any printer that isn't already registered. Hosts already in the system are skipped.
                    </p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">IP range <span class="text-danger">*</span></label>
                        <input type="text" name="range_input" class="form-control font-monospace"
                               placeholder="192.168.1.0/24  or  192.168.1.1-254" required maxlength="255">
                        <div class="form-text">CIDR (<code>192.168.1.0/24</code>) or range (<code>192.168.1.1-254</code>). Capped at 256 IPs per scan.</div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Assign to branch</label>
                            <select name="branch_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ request('branch') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Community</label>
                            <input type="text" name="snmp_community" class="form-control font-monospace"
                                   value="public" maxlength="100" placeholder="public">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Version</label>
                            <select name="snmp_version" class="form-select">
                                <option value="v2c" selected>v2c</option>
                                <option value="v1">v1</option>
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-warning small mt-3 mb-0 py-2">
                        <i class="bi bi-clock-history me-1"></i>
                        A full /24 sweep can take up to a minute. Please wait after starting — don't close the tab.
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm" id="discoverPrintersSubmit">
                        <i class="bi bi-search me-1"></i>Start Scan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Disable the button + show a spinner so the admin knows the (synchronous) scan is running.
document.getElementById('discoverPrintersForm')?.addEventListener('submit', function () {
    const btn = document.getElementById('discoverPrintersSubmit');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Scanning…';
    }
});
</script>
@endpush
