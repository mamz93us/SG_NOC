@php($isEdit = isset($po))

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ $isEdit ? route('admin.itam.purchase-orders.update', $po) : route('admin.itam.purchase-orders.store') }}"
              x-data="poForm({{ $isEdit ? 'true' : 'false' }})">
            @csrf
            @if($isEdit) @method('PUT') @endif

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">PO Number <span class="text-danger">*</span></label>
                    <input type="text" name="po_number" class="form-control font-monospace"
                           value="{{ old('po_number', $po->po_number ?? '') }}" {{ $isEdit ? 'readonly' : '' }} required maxlength="32" placeholder="e.g. PO:00001">
                    @error('po_number') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">PO Date <span class="text-danger">*</span></label>
                    <input type="date" name="po_date" class="form-control"
                           value="{{ old('po_date', isset($po) ? $po->po_date->format('Y-m-d') : now()->format('Y-m-d')) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Supplier</label>
                    <select name="supplier_id" class="form-select">
                        <option value="">—</option>
                        @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" {{ old('supplier_id', $po->supplier_id ?? '') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Currency</label>
                    <input type="text" name="currency" class="form-control text-uppercase" maxlength="3"
                           value="{{ old('currency', $po->currency ?? 'SAR') }}" required>
                </div>

                @if($isEdit)
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        @foreach(\App\Models\PurchaseOrder::STATUSES as $st)
                        <option value="{{ $st }}" {{ old('status', $po->status) == $st ? 'selected' : '' }}>{{ ucfirst($st) }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tax</label>
                    <input type="number" step="0.01" name="tax" class="form-control"
                           value="{{ old('tax', $po->tax ?? 0) }}" min="0">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes', $po->notes ?? '') }}</textarea>
                </div>
            </div>

            @if(!$isEdit)
            <hr class="my-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-list-ul me-1"></i>Line Items</h6>
                <button type="button" class="btn btn-sm btn-outline-primary" @click="addLine">
                    <i class="bi bi-plus-lg me-1"></i>Add Line
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:120px">Type</th>
                            <th>Name</th>
                            <th>Manufacturer</th>
                            <th>Model</th>
                            <th>Serial / Detail</th>
                            <th>Branch / Store</th>
                            <th style="width:70px">Qty</th>
                            <th style="width:120px">Unit Cost</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, idx) in items" :key="idx">
                            <tr>
                                <td>
                                    <select :name="`items[${idx}][line_type]`" class="form-select form-select-sm" x-model="item.line_type">
                                        <option value="device">Device</option>
                                        <option value="accessory">Accessory</option>
                                        <option value="license">License</option>
                                    </select>
                                </td>
                                <td><input type="text" :name="`items[${idx}][name]`" class="form-control form-control-sm" x-model="item.name" required></td>
                                <td><input type="text" :name="`items[${idx}][manufacturer]`" class="form-control form-control-sm" x-model="item.manufacturer"></td>
                                <td><input type="text" :name="`items[${idx}][model]`" class="form-control form-control-sm" x-model="item.model"></td>
                                <td>
                                    <template x-if="item.line_type === 'device'">
                                        <input type="text" :name="`items[${idx}][serial_number]`" class="form-control form-control-sm font-monospace" x-model="item.serial_number" placeholder="Serial (required)" required>
                                    </template>
                                    <template x-if="item.line_type === 'accessory'">
                                        <select :name="`items[${idx}][category]`" class="form-select form-select-sm" x-model="item.category">
                                            <option value="">Category…</option>
                                            @foreach(\App\Models\Accessory::CATEGORIES as $cat)
                                            <option value="{{ $cat }}">{{ ucfirst($cat) }}</option>
                                            @endforeach
                                        </select>
                                    </template>
                                    <template x-if="item.line_type === 'license'">
                                        <div class="d-flex gap-1">
                                            <select :name="`items[${idx}][license_type]`" class="form-select form-select-sm" x-model="item.license_type" required>
                                                <option value="">Type…</option>
                                                @foreach(\App\Models\License::TYPES as $lt)
                                                <option value="{{ $lt }}">{{ ucfirst($lt) }}</option>
                                                @endforeach
                                            </select>
                                            <input type="number" :name="`items[${idx}][seats]`" min="1" class="form-control form-control-sm" placeholder="Seats" x-model="item.seats" required>
                                            <input type="date" :name="`items[${idx}][expiry_date]`" class="form-control form-control-sm" x-model="item.expiry_date">
                                        </div>
                                    </template>
                                </td>
                                <td>
                                    <select :name="`items[${idx}][branch_id]`" class="form-select form-select-sm" x-model="item.branch_id">
                                        <option value="">Universal</option>
                                        @foreach($branches as $b)
                                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="number" min="1" :name="`items[${idx}][quantity]`" class="form-control form-control-sm" x-model.number="item.quantity" required></td>
                                <td><input type="number" min="0" step="0.01" :name="`items[${idx}][unit_cost]`" class="form-control form-control-sm" x-model.number="item.unit_cost" required></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger" @click="removeLine(idx)"><i class="bi bi-trash"></i></button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="6"></td>
                            <td class="fw-semibold text-end">Subtotal</td>
                            <td class="fw-semibold" x-text="subtotal().toFixed(2)"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @error('items') <div class="alert alert-danger small mt-2">{{ $message }}</div> @enderror
            @endif

            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>{{ $isEdit ? 'Update' : 'Create PO' }}</button>
                <a href="{{ route('admin.itam.purchase-orders.index') }}" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@unless($isEdit)
<script>
function poForm() {
    return {
        items: [
            { line_type: 'device', name: '', manufacturer: '', model: '', serial_number: '', branch_id: '', quantity: 1, unit_cost: 0, category: '', license_type: '', seats: 1, expiry_date: '' }
        ],
        addLine() {
            this.items.push({ line_type: 'device', name: '', manufacturer: '', model: '', serial_number: '', branch_id: '', quantity: 1, unit_cost: 0, category: '', license_type: '', seats: 1, expiry_date: '' });
        },
        removeLine(idx) {
            if (this.items.length > 1) this.items.splice(idx, 1);
        },
        subtotal() {
            return this.items.reduce((sum, it) => sum + (Number(it.quantity) || 0) * (Number(it.unit_cost) || 0), 0);
        },
    };
}
</script>
@endunless
