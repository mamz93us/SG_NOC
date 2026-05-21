@extends('layouts.admin')
@section('content')

<div class="mb-4">
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-globe2 me-2 text-primary"></i>{{ isset($isp) ? 'Edit' : 'Add' }} ISP Connection
    </h4>
    <small class="text-muted">
        <a href="{{ route('admin.network.isp.index') }}" class="text-decoration-none">ISP Connections</a> / {{ isset($isp) ? 'Edit' : 'New' }}
        — <a href="{{ route('admin.network.isp-providers.index') }}" class="text-decoration-none">Manage Providers</a>
    </small>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST"
              action="{{ isset($isp) ? route('admin.network.isp.update', $isp) : route('admin.network.isp.store') }}"
              x-data="ispForm({
                  providers: @js($providers->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'default_currency' => $p->default_currency, 'packages' => $p->packages->map(fn($pk) => ['id' => $pk->id, 'name' => $pk->name, 'speed_down' => $pk->speed_down, 'speed_up' => $pk->speed_up, 'monthly_cost' => $pk->monthly_cost, 'currency' => $pk->currency])])->values()),
                  selectedProviderId: @js(old('isp_provider_id', $isp->isp_provider_id ?? '')),
                  selectedPackageId:  @js(old('isp_provider_package_id', $isp->isp_provider_package_id ?? '')),
                  initialBillingDay:  @js((int) old('billing_day', $isp->billing_day ?? 0)),
                  initialCurrency:    @js(old('currency', $isp->currency ?? 'EGP'))
              })">
            @csrf
            @if(isset($isp)) @method('PUT') @endif

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Branch <span class="text-danger">*</span></label>
                    <select name="branch_id" class="form-select" required>
                        <option value="">Select Branch</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id', $isp->branch_id ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Provider <span class="text-danger">*</span></label>
                    <div class="d-flex gap-1">
                        <select name="isp_provider_id" class="form-select" required x-model="selectedProviderId" @change="onProviderChange()">
                            <option value="">— Select Provider —</option>
                            <template x-for="p in providers" :key="p.id">
                                <option :value="p.id" x-text="p.name"></option>
                            </template>
                        </select>
                        <a href="{{ route('admin.network.isp-providers.index') }}" class="btn btn-outline-primary" title="Manage providers" target="_blank">
                            <i class="bi bi-pencil-square"></i>
                        </a>
                    </div>
                    @error('isp_provider_id') <small class="text-danger">{{ $message }}</small> @enderror
                    <small class="text-muted">Need a provider that's not listed? Click the pencil to add it.</small>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Circuit ID</label>
                    <input type="text" name="circuit_id" class="form-control" value="{{ old('circuit_id', $isp->circuit_id ?? '') }}" placeholder="ISP circuit reference">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Download Speed (Mbps)</label>
                    <input type="number" name="speed_down" class="form-control" value="{{ old('speed_down', $isp->speed_down ?? '') }}" min="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Upload Speed (Mbps)</label>
                    <input type="number" name="speed_up" class="form-control" value="{{ old('speed_up', $isp->speed_up ?? '') }}" min="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Static IP</label>
                    <input type="text" name="static_ip" class="form-control font-monospace" value="{{ old('static_ip', $isp->static_ip ?? '') }}" placeholder="e.g. 203.0.113.10">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Gateway</label>
                    <input type="text" name="gateway" class="form-control font-monospace" value="{{ old('gateway', $isp->gateway ?? '') }}" placeholder="e.g. 203.0.113.1">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Subnet</label>
                    <input type="text" name="subnet" class="form-control font-monospace" value="{{ old('subnet', $isp->subnet ?? '') }}" placeholder="e.g. /29 or 255.255.255.248">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Router Device</label>
                    <select name="router_device_id" class="form-select">
                        <option value="">None</option>
                        @foreach($routers as $r)
                        <option value="{{ $r->id }}" {{ old('router_device_id', $isp->router_device_id ?? '') == $r->id ? 'selected' : '' }}>{{ $r->name }} ({{ ucfirst($r->type) }})</option>
                        @endforeach
                    </select>
                </div>

                {{-- ── Account & Billing ── --}}
                <div class="col-12 mt-2">
                    <hr class="my-2">
                    <h6 class="fw-semibold text-primary"><i class="bi bi-credit-card me-1"></i>Account & Billing</h6>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Account Number</label>
                    <input type="text" name="account_number" class="form-control font-monospace" value="{{ old('account_number', $isp->account_number ?? '') }}" placeholder="Customer / account #">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Package</label>
                    <select name="isp_provider_package_id" class="form-select" x-model="selectedPackageId" @change="onPackageChange()">
                        <option value="">— Select Package —</option>
                        <template x-for="pk in availablePackages()" :key="pk.id">
                            <option :value="pk.id" x-text="pk.name + (pk.speed_down ? ' (' + pk.speed_down + '/' + pk.speed_up + ' Mbps)' : '')"></option>
                        </template>
                    </select>
                    <small class="text-muted">Filtered by selected provider</small>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Connection Type</label>
                    <select name="connection_type" class="form-select">
                        <option value="">—</option>
                        @foreach(\App\Models\IspConnection::CONNECTION_TYPES as $t)
                        <option value="{{ $t }}" {{ old('connection_type', $isp->connection_type ?? '') === $t ? 'selected' : '' }}>{{ strtoupper($t) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Customer Type</label>
                    <select name="customer_type" class="form-select">
                        <option value="">—</option>
                        @foreach(\App\Models\IspConnection::CUSTOMER_TYPES as $t)
                        <option value="{{ $t }}" {{ old('customer_type', $isp->customer_type ?? '') === $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Payment Type</label>
                    <select name="payment_type" class="form-select">
                        <option value="">—</option>
                        @foreach(\App\Models\IspConnection::PAYMENT_TYPES as $t)
                        <option value="{{ $t }}" {{ old('payment_type', $isp->payment_type ?? '') === $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Monthly Cost</label>
                    <input type="number" name="monthly_cost" class="form-control" value="{{ old('monthly_cost', $isp->monthly_cost ?? '') }}" min="0" step="0.01">
                    <small class="text-muted">Auto-filled from package if blank</small>
                </div>

                <div class="col-md-1">
                    <label class="form-label fw-semibold">Currency</label>
                    <select name="currency" class="form-select" x-model="currency">
                        @foreach(\App\Models\IspConnection::CURRENCIES as $cur)
                        <option value="{{ $cur }}">{{ $cur }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- ── Renewal Cycle (replaces single renewal_date) ── --}}
                <div class="col-12 mt-2">
                    <hr class="my-2">
                    <h6 class="fw-semibold text-primary"><i class="bi bi-arrow-repeat me-1"></i>Renewal Cycle</h6>
                    <small class="text-muted d-block mb-2">The contract is open-ended (always working). Renewal repeats monthly on the billing day.</small>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Billing Day <span class="text-danger">*</span></label>
                    <input type="number" name="billing_day" min="1" max="31" class="form-control"
                           value="{{ old('billing_day', $isp->billing_day ?? '') }}"
                           x-model.number="billingDay"
                           placeholder="1–31" required>
                    <small class="text-muted">Day of the month for billing</small>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Remind Before (Days)</label>
                    <input type="number" name="renewal_remind_days" class="form-control" value="{{ old('renewal_remind_days', $isp->renewal_remind_days ?? 2) }}" min="1" max="90">
                    <small class="text-muted">Days before each cycle to send the reminder</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Upcoming Renewals</label>
                    <div class="border rounded p-2 bg-light small" style="min-height:38px;">
                        <template x-if="billingDay && billingDay >= 1 && billingDay <= 31">
                            <div class="d-flex flex-wrap gap-2">
                                <template x-for="d in upcomingCycles()" :key="d">
                                    <span class="badge bg-info text-dark font-monospace" x-text="d"></span>
                                </template>
                            </div>
                        </template>
                        <template x-if="!billingDay">
                            <em class="text-muted">Pick a billing day to see the next 6 renewal dates.</em>
                        </template>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes', $isp->notes ?? '') }}</textarea>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>{{ isset($isp) ? 'Update' : 'Create' }}
                </button>
                <a href="{{ route('admin.network.isp.index') }}" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
function ispForm(initial) {
    return {
        providers:          initial.providers || [],
        selectedProviderId: initial.selectedProviderId || '',
        selectedPackageId:  initial.selectedPackageId || '',
        billingDay:         initial.initialBillingDay || null,
        currency:           initial.initialCurrency || 'EGP',

        availablePackages() {
            const pid = String(this.selectedProviderId || '');
            const p = this.providers.find(x => String(x.id) === pid);
            return p ? p.packages : [];
        },
        onProviderChange() {
            // If the previously-selected package doesn't belong to the new
            // provider, clear it so we don't submit a cross-provider mismatch.
            const valid = this.availablePackages().some(pk => String(pk.id) === String(this.selectedPackageId));
            if (! valid) this.selectedPackageId = '';
            // Adopt provider default currency if the user hasn't overridden it.
            const p = this.providers.find(x => String(x.id) === String(this.selectedProviderId));
            if (p && p.default_currency) this.currency = p.default_currency;
        },
        onPackageChange() {
            const pk = this.availablePackages().find(x => String(x.id) === String(this.selectedPackageId));
            if (pk && pk.currency) this.currency = pk.currency;
        },
        upcomingCycles(count = 6) {
            const out = [];
            const day = parseInt(this.billingDay, 10);
            if (!day || day < 1 || day > 31) return out;
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let y = today.getFullYear();
            let m = today.getMonth(); // 0-indexed
            // last day of this month
            const lastDay = new Date(y, m + 1, 0).getDate();
            let candidate = new Date(y, m, Math.min(day, lastDay));
            if (candidate < today) {
                m += 1;
                const ld2 = new Date(y, m + 1, 0).getDate();
                candidate = new Date(y, m, Math.min(day, ld2));
            }
            for (let i = 0; i < count; i++) {
                const yy = candidate.getFullYear();
                const mm = String(candidate.getMonth() + 1).padStart(2, '0');
                const dd = String(candidate.getDate()).padStart(2, '0');
                out.push(`${dd}/${mm}/${yy}`);
                m = candidate.getMonth() + 1;
                const ld = new Date(candidate.getFullYear(), m + 1, 0).getDate();
                candidate = new Date(candidate.getFullYear(), m, Math.min(day, ld));
            }
            return out;
        },
    };
}
</script>
@endsection
