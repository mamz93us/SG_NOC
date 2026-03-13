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
        <label class="form-label">Cost ($)</label>
        <input type="number" name="purchase_cost" class="form-control" step="0.01" min="0">
    </div>
</div>
<div class="mt-3">
    <label class="form-label">Notes</label>
    <textarea name="notes" class="form-control" rows="2"></textarea>
</div>
