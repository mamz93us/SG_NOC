@extends('layouts.admin')
@section('title', 'New Scrap Request')

@section('content')
<div class="container-fluid py-4" x-data="scrapForm()">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-trash3 me-2"></i>New Asset Scrap Request</h4>
        <a href="{{ route('admin.itam.scrap.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to List
        </a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.itam.scrap.store') }}" enctype="multipart/form-data" @submit="onSubmit($event)">
        @csrf

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <strong>1. Search & Select Items to Scrap</strong>
            </div>
            <div class="card-body">
                <div class="input-group mb-3">
                    <input type="text" id="scrapSearchInput" value="{{ request('q') }}" class="form-control"
                           placeholder="Search by asset code, name, serial number, or category..."
                           onkeydown="if(event.key==='Enter'){event.preventDefault();scrapSearchGo();}">
                    <button type="button" class="btn btn-outline-secondary" onclick="scrapSearchGo()">
                        <i class="bi bi-search"></i> Search
                    </button>
                    @if(request('q'))
                        <a href="{{ route('admin.itam.scrap.create') }}" class="btn btn-outline-secondary" title="Clear search">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    @endif
                </div>

                <h6 class="text-uppercase text-muted small fw-semibold mb-2"><i class="bi bi-laptop me-1"></i>Devices</h6>
                <div class="table-responsive mb-3" style="max-height:300px;overflow:auto">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40px"></th>
                                <th>Asset Code</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Serial</th>
                                <th>Branch</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($devices as $d)
                                <tr>
                                    <td><input type="checkbox" name="device_ids[]" value="{{ $d->id }}" class="form-check-input" x-model="selected"></td>
                                    <td><code>{{ $d->asset_code }}</code></td>
                                    <td>{{ $d->name }}</td>
                                    <td><span class="badge bg-secondary">{{ $d->type }}</span></td>
                                    <td><span class="badge {{ $d->statusBadgeClass() }}">{{ $d->status }}</span></td>
                                    <td>{{ $d->serial_number ?? '—' }}</td>
                                    <td>{{ $d->branch?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center py-3 text-muted">No devices found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <h6 class="text-uppercase text-muted small fw-semibold mb-2"><i class="bi bi-box-seam me-1"></i>Accessories</h6>
                <div class="table-responsive" style="max-height:300px;overflow:auto">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40px"></th>
                                <th>Asset Code</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th class="text-center">Available</th>
                                <th>Branch</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($accessories as $a)
                                <tr>
                                    <td><input type="checkbox" name="accessory_ids[]" value="{{ $a->id }}" class="form-check-input" x-model="accessoriesSelected"></td>
                                    <td><code>{{ $a->asset_code ?? '—' }}</code></td>
                                    <td>{{ $a->name }}</td>
                                    <td><span class="badge bg-secondary">{{ $a->category ?: '—' }}</span></td>
                                    <td class="text-center">{{ $a->quantity_available }} / {{ $a->quantity_total }}</td>
                                    <td>{{ $a->branch?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center py-3 text-muted">No accessories found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-2 text-end small text-muted">
                    <span x-text="selected.length"></span> device(s),
                    <span x-text="accessoriesSelected.length"></span> accessor(y/ies) selected
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>2. Reason & Disposal</strong></div>
            <div class="card-body row g-3">
                <div class="col-12">
                    <label class="form-label">Reason for Scrapping</label>
                    <textarea name="reason" class="form-control" rows="3" maxlength="2000" required placeholder="Describe why these assets need to be scrapped (damaged, end-of-life, etc.)"></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Disposal Method</label>
                    <select name="disposal_method" class="form-select" required>
                        <option value="recycle">Recycle</option>
                        <option value="donate">Donate</option>
                        <option value="destroy">Destroy</option>
                        <option value="sell">Sell</option>
                        <option value="return_to_supplier">Return to Supplier</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Photos (optional, max 5)</label>
                    <input type="file" name="photos[]" multiple accept="image/*" class="form-control">
                    <small class="text-muted">Photos help document the condition for audit purposes.</small>
                </div>
            </div>
        </div>

        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            This request will go through 2 approval steps: <strong>IT Manager</strong> &rarr; <strong>Super Admin</strong>. The asset(s) will only be marked as scrapped after both approvals.
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('admin.itam.scrap.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-danger">
                <i class="bi bi-send me-1"></i>Submit Scrap Request
            </button>
        </div>
    </form>
</div>

<script>
function scrapForm() {
    return {
        selected: [],
        accessoriesSelected: [],
        onSubmit(e) {
            const total = this.selected.length + this.accessoriesSelected.length;
            if (total === 0) {
                e.preventDefault();
                alert('Please select at least one device or accessory to scrap.');
            } else if (!confirm(`Submit scrap request for ${total} item(s)?`)) {
                e.preventDefault();
            }
        }
    };
}

function scrapSearchGo() {
    const q = document.getElementById('scrapSearchInput').value.trim();
    const url = new URL(window.location.href);
    if (q) {
        url.searchParams.set('q', q);
    } else {
        url.searchParams.delete('q');
    }
    window.location.href = url.toString();
}
</script>
@endsection
