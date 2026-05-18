@php($isEdit = isset($po))

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ $isEdit ? route('admin.itam.purchase-orders.update', $po) : route('admin.itam.purchase-orders.store') }}"
              x-data="poForm({
                  poNumber: @js(old('po_number', $po->po_number ?? '')),
                  deviceModels: @js($deviceModels ?? []),
                  deviceModelsRoute: @js(route('admin.devices.models.store')),
                  csrf: @js(csrf_token())
              })">
            @csrf
            @if($isEdit) @method('PUT') @endif

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">PO Number <span class="text-danger">*</span></label>
                    <input type="text" name="po_number" class="form-control font-monospace"
                           x-model="poNumber"
                           {{ $isEdit ? 'readonly' : '' }} required maxlength="32" placeholder="e.g. PO:00001">
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
                    <select name="currency" class="form-select" required>
                        @foreach($currencies as $code)
                        <option value="{{ $code }}" {{ old('currency', $po->currency ?? $defaultCurrency) === $code ? 'selected' : '' }}>{{ $code }}</option>
                        @endforeach
                    </select>
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
                            <th style="width:110px">Type</th>
                            <th style="width:170px">Name</th>
                            <th style="width:230px">Model</th>
                            <th>Serial / Detail</th>
                            <th>Branch / Store</th>
                            <th style="width:60px">Qty</th>
                            <th style="width:110px">Unit Cost</th>
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
                                <td>
                                    <input type="text" :name="`items[${idx}][name]`" class="form-control form-control-sm" x-model="item.name" placeholder="e.g. Laptop" required>
                                </td>

                                {{-- Model / Manufacturer combined --}}
                                <td>
                                    <template x-if="item.line_type === 'device'">
                                        <div class="d-flex gap-1">
                                            <select class="form-select form-select-sm" x-model="item.device_model_id" @change="onModelChange(item)">
                                                <option value="">— Pick a model —</option>
                                                <template x-for="m in deviceModels" :key="m.id">
                                                    <option :value="m.id" x-text="(m.manufacturer ? m.manufacturer + ' ' : '') + m.name"></option>
                                                </template>
                                            </select>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#newDeviceModelModal" @click="targetLineIdx = idx" title="Add new model">
                                                <i class="bi bi-plus-lg"></i>
                                            </button>
                                            <input type="hidden" :name="`items[${idx}][manufacturer]`" :value="item.manufacturer">
                                            <input type="hidden" :name="`items[${idx}][model]`" :value="item.model">
                                        </div>
                                    </template>
                                    <template x-if="item.line_type !== 'device'">
                                        <input type="text" :name="`items[${idx}][manufacturer]`" class="form-control form-control-sm" x-model="item.manufacturer" placeholder="Vendor / brand">
                                    </template>
                                </td>

                                {{-- Serial / per-type detail --}}
                                <td>
                                    <template x-if="item.line_type === 'device'">
                                        <input type="text" :name="`items[${idx}][serial_number]`" class="form-control form-control-sm font-monospace" x-model="item.serial_number" placeholder="Serial (optional)">
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
                            {{-- Live-rename preview row, only for device lines --}}
                            <template x-if="item.line_type === 'device'">
                                <tr class="bg-light">
                                    <td colspan="8" class="small text-muted">
                                        <i class="bi bi-arrow-return-right me-1"></i>
                                        <strong>Device will be saved as:</strong>
                                        <span class="font-monospace text-dark" x-text="previewName(item) || '— (fill in the line above)'"></span>
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="5"></td>
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

            {{-- ── New Device Model Modal (Alpine + fetch) ───────────────── --}}
            @unless($isEdit)
            <div class="modal fade" id="newDeviceModelModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Device Model</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Manufacturer</label>
                                    <input type="text" class="form-control" x-model="newModel.manufacturer" placeholder="e.g. Lenovo">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Model <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" x-model="newModel.name" placeholder="e.g. ThinkPad T14">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Device Type</label>
                                    <input type="text" class="form-control" x-model="newModel.device_type" placeholder="e.g. laptop">
                                </div>
                            </div>
                            <div class="alert alert-danger small mt-3" x-show="newModelError" x-text="newModelError" style="display:none;"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" @click="saveNewModel()" :disabled="newModelSaving">
                                <span x-show="!newModelSaving"><i class="bi bi-check-lg me-1"></i>Save</span>
                                <span x-show="newModelSaving"><i class="bi bi-hourglass-split me-1"></i>Saving…</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            @endunless
        </form>
    </div>
</div>

@unless($isEdit)
<script>
function poForm(initial) {
    return {
        poNumber:      initial.poNumber || '',
        deviceModels:  initial.deviceModels || [],
        targetLineIdx: null,
        newModel:      { manufacturer: '', name: '', device_type: '' },
        newModelError: '',
        newModelSaving: false,

        items: [
            { line_type: 'device', name: '', manufacturer: '', model: '', device_model_id: '', serial_number: '', branch_id: '', quantity: 1, unit_cost: 0, category: '', license_type: '', seats: 1, expiry_date: '' }
        ],

        addLine() {
            this.items.push({ line_type: 'device', name: '', manufacturer: '', model: '', device_model_id: '', serial_number: '', branch_id: '', quantity: 1, unit_cost: 0, category: '', license_type: '', seats: 1, expiry_date: '' });
        },
        removeLine(idx) {
            if (this.items.length > 1) this.items.splice(idx, 1);
        },
        subtotal() {
            return this.items.reduce((sum, it) => sum + (Number(it.quantity) || 0) * (Number(it.unit_cost) || 0), 0);
        },
        onModelChange(item) {
            const m = this.deviceModels.find(x => String(x.id) === String(item.device_model_id));
            if (m) {
                item.manufacturer = m.manufacturer || '';
                item.model        = m.name || '';
            } else {
                item.manufacturer = '';
                item.model        = '';
            }
        },
        previewName(item) {
            // Mirrors PurchaseOrderItem::buildDeviceName()
            const parts = [item.name, item.manufacturer, item.model, item.serial_number, 'PO:' + (this.poNumber || '?')]
                .filter(p => p && String(p).trim() !== '');
            return parts.join(' ');
        },

        async saveNewModel() {
            this.newModelError  = '';
            this.newModelSaving = true;
            try {
                const r = await fetch(initial.deviceModelsRoute, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': initial.csrf,
                        'Accept':       'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(this.newModel),
                });
                const data = await r.json();
                if (!r.ok) {
                    this.newModelError = data?.message || 'Could not save model.';
                    return;
                }
                // Add to dropdown and select it on the target line
                const created = { id: data.id, name: this.newModel.name, manufacturer: this.newModel.manufacturer, device_type: this.newModel.device_type };
                this.deviceModels.push(created);
                if (this.targetLineIdx !== null) {
                    this.items[this.targetLineIdx].device_model_id = String(created.id);
                    this.onModelChange(this.items[this.targetLineIdx]);
                }
                this.newModel = { manufacturer: '', name: '', device_type: '' };
                bootstrap.Modal.getInstance(document.getElementById('newDeviceModelModal')).hide();
            } catch (e) {
                this.newModelError = 'Network error: ' + e.message;
            } finally {
                this.newModelSaving = false;
            }
        },
    };
}
</script>
@endunless
