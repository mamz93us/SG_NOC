<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label">License Name <span class="text-danger">*</span></label>
        <input type="text" name="license_name" class="form-control" required>
    </div>
    <div class="col-md-4">
        <label class="form-label">Type <span class="text-danger">*</span></label>
        <select name="license_type" class="form-select" required>
            @foreach(['subscription','perpetual','oem','freeware'] as $t)
            <option value="{{ $t }}">{{ ucfirst($t) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Vendor</label>
        <input type="text" name="vendor" class="form-control">
    </div>
    <div class="col-md-3">
        <label class="form-label">Seats <span class="text-danger">*</span></label>
        <input type="number" name="seats" class="form-control" value="1" min="1" required>
    </div>
    <div class="col-md-3">
        <label class="form-label">Cost ($)</label>
        <input type="number" name="cost" class="form-control" step="0.01" min="0">
    </div>
    <div class="col-md-6">
        <label class="form-label">Purchase Date</label>
        <input type="date" name="purchase_date" class="form-control">
    </div>
    <div class="col-md-6">
        <label class="form-label">Expiry Date</label>
        <input type="date" name="expiry_date" class="form-control">
    </div>
    <div class="col-12">
        <label class="form-label">License Key <small class="text-muted">(stored encrypted)</small></label>
        <input type="text" name="license_key" class="form-control font-monospace">
    </div>
    <div class="col-12">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="2"></textarea>
    </div>
</div>
