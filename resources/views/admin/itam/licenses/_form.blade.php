<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label">License Name <span class="text-danger">*</span></label>
        <input type="text" name="license_name" class="form-control" required>
    </div>
    <div class="col-md-4">
        <label class="form-label">Type <span class="text-danger">*</span></label>
        <select name="license_type" class="form-select" required>
            @foreach(\App\Models\License::TYPES as $t)
            <option value="{{ $t }}">{{ \App\Models\License::TYPE_LABELS[$t] ?? ucfirst($t) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Supplier</label>
        <select name="supplier_id" class="form-select">
            <option value="">— None —</option>
            @foreach($suppliers ?? [] as $sup)
            <option value="{{ $sup->id }}" {{ old('supplier_id') == $sup->id ? 'selected' : '' }}>{{ $sup->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Linked Azure SKU <small class="text-muted">— flows price/expiry into Azure user assignments</small></label>
        <select name="identity_license_id" class="form-select">
            <option value="">— None —</option>
            @foreach($identityLicenses ?? [] as $sku)
            <option value="{{ $sku->id }}" {{ old('identity_license_id', $linkedSkuId ?? '') == $sku->id ? 'selected' : '' }}>{{ $sku->sku_part_number }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Seats <span class="text-danger">*</span></label>
        <input type="number" name="seats" class="form-control" value="1" min="1" required>
    </div>
    <div class="col-md-3">
        <label class="form-label">Billing Cycle <span class="text-danger">*</span></label>
        <select name="billing_cycle" class="form-select" required>
            @foreach(\App\Models\License::BILLING_CYCLES as $c)
            <option value="{{ $c }}" {{ old('billing_cycle', 'one_time') === $c ? 'selected' : '' }}>
                {{ \App\Models\License::BILLING_CYCLE_LABELS[$c] }}
            </option>
            @endforeach
        </select>
        <div class="form-text">How often the cost below is charged.</div>
    </div>
    <div class="col-md-6">
        <label class="form-label">Cost <span class="text-muted small">(per seat, per billing cycle, VAT included)</span></label>
        <div class="input-group">
            <input type="number" name="cost" class="form-control" step="0.01" min="0">
            <select name="currency" class="form-select" style="max-width:90px">
                @foreach(\App\Support\Currency::CODES as $code)
                <option value="{{ $code }}" {{ old('currency', \App\Support\Currency::DEFAULT) === $code ? 'selected' : '' }}>{{ $code }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-text">Price per seat as invoiced, tax included. Totals are calculated from seats.</div>
    </div>
    <div class="col-md-4">
        <label class="form-label">Payment Method</label>
        <select name="payment_method" class="form-select">
            <option value="">— Not set —</option>
            @foreach(\App\Models\License::PAYMENT_METHODS as $m)
            <option value="{{ $m }}">{{ \App\Models\License::PAYMENT_METHOD_LABELS[$m] }}</option>
            @endforeach
        </select>
        <div class="form-text">Card renewals charge themselves; wire transfers need raising.</div>
    </div>
    <div class="col-md-4">
        <label class="form-label">Paid From <small class="text-muted">(card / account)</small></label>
        <input type="text" name="payment_account" class="form-control" maxlength="100" placeholder="e.g. Company Visa ••4821">
        <div class="form-text">Shown to finance on the payments report.</div>
    </div>
    <div class="col-md-4">
        <label class="form-label">Purchase Date</label>
        <input type="date" name="purchase_date" class="form-control">
    </div>
    <div class="col-md-6">
        <label class="form-label">Expiry / Next Renewal Date</label>
        <input type="date" name="expiry_date" class="form-control">
        <div class="form-text">For a recurring subscription this is the renewal day — the reports project it forward each cycle.</div>
    </div>
    <div class="col-12">
        <label class="form-label">License Key <small class="text-muted">(stored encrypted — leave blank when editing to keep existing)</small></label>
        <input type="text" name="license_key" class="form-control font-monospace">
    </div>
    <div class="col-12">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="2"></textarea>
    </div>
</div>
