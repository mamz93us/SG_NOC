@extends('layouts.admin')
@section('title', 'Domain Lookup')

@section('content')
<div class="container-fluid py-4" x-data="domainLookup()">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-search me-2"></i>Domain Availability Lookup</h4>
            <small class="text-muted">Check if a domain name is available for registration</small>
        </div>
        <a href="{{ route('admin.network.dns.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">GoDaddy Account</label>
                            <select class="form-select" x-model="accountId">
                                @foreach($accounts as $acct)
                                <option value="{{ $acct->id }}">{{ $acct->label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Domain Name</label>
                            <input type="text" class="form-control" x-model="domain" placeholder="example.com" @keydown.enter="check()">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-primary w-100" @click="check()" :disabled="loading || !domain.trim()">
                                <span x-show="loading" class="spinner-border spinner-border-sm me-1"></span>
                                <span x-show="!loading"><i class="bi bi-search me-1"></i></span>
                                Check
                            </button>
                        </div>
                    </div>

                    <div x-show="error" class="alert alert-danger mt-3 small" x-text="error"></div>

                    {{-- Result --}}
                    <template x-if="result !== null">
                        <div class="mt-4">
                            <div class="card" :class="result.available ? 'border-success' : 'border-danger'">
                                <div class="card-body text-center py-4">
                                    <template x-if="result.available">
                                        <div>
                                            <i class="bi bi-check-circle-fill text-success display-4"></i>
                                            <h5 class="mt-2 text-success" x-text="result.domain + ' is available!'"></h5>
                                            <template x-if="result.price">
                                                <p class="text-muted">
                                                    Price: <strong x-text="(result.price / 1000000).toFixed(2) + ' ' + (result.currency || 'USD')"></strong>
                                                    <span x-show="result.period" x-text="'/ ' + result.period + ' year(s)'"></span>
                                                </p>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="!result.available">
                                        <div>
                                            <i class="bi bi-x-circle-fill text-danger display-4"></i>
                                            <h5 class="mt-2 text-danger" x-text="result.domain + ' is not available'"></h5>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function domainLookup() {
    return {
        accountId: '{{ $accounts->first()?->id }}',
        domain: '',
        loading: false,
        error: '',
        result: null,

        async check() {
            if (!this.domain.trim() || !this.accountId) return;
            this.loading = true;
            this.error = '';
            this.result = null;

            try {
                const res = await fetch("{{ route('admin.network.dns.lookup.check') }}", {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ account_id: this.accountId, domain: this.domain.trim() })
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    this.error = data.message || 'Lookup failed.';
                    return;
                }
                this.result = data.data;
            } catch (e) {
                this.error = 'Network error. Please try again.';
            } finally {
                this.loading = false;
            }
        }
    };
}
</script>
@endpush
@endsection
