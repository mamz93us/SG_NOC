<div class="mb-3">
    <label class="form-label">Asset Code</label>
    <input type="text" name="asset_code" class="form-control font-monospace" placeholder="Auto-generated if blank — e.g. SG-ACC-000042">
</div>
<div class="mb-3">
    <label class="form-label">Name <span class="text-danger">*</span></label>
    <input type="text" name="name" class="form-control" required>
</div>
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label">Category</label>
        <select name="category" class="form-select">
            <option value="">Select category...</option>
            @foreach($categories as $cat)
            <option value="{{ $cat }}">{{ ucfirst($cat) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Supplier</label>
        <select name="supplier_id" class="form-select">
            <option value="">None</option>
            @foreach($suppliers as $sup)
            <option value="{{ $sup->id }}">{{ $sup->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">Total Qty <span class="text-danger">*</span></label>
        <input type="number" name="quantity_total" class="form-control" min="0" value="0" required>
    </div>
    <div class="col-md-4">
        <label class="form-label">Available Qty <span class="text-danger">*</span></label>
        <input type="number" name="quantity_available" class="form-control" min="0" value="0" required>
    </div>
    <div class="col-md-4">
        <label class="form-label">Cost</label>
        <div class="input-group">
            <input type="number" name="purchase_cost" class="form-control" step="0.01" min="0">
            <select name="currency" class="form-select" style="max-width:80px">
                @foreach(\App\Support\Currency::CODES as $code)
                <option value="{{ $code }}" {{ old('currency', \App\Support\Currency::DEFAULT) === $code ? 'selected' : '' }}>{{ $code }}</option>
                @endforeach
            </select>
        </div>
    </div>
</div>
<div class="mt-3">
    <label class="form-label">Notes</label>
    <textarea name="notes" class="form-control" rows="2"></textarea>
</div>
