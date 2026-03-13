@extends('layouts.admin')
@section('title', 'Asset Label — ' . $device->asset_code)

@push('styles')
<style>
@media print {
    nav, .navbar, .no-print { display: none !important; }
    body { background: white !important; }
    .label-card { border: none !important; box-shadow: none !important; }
    .print-btn { display: none !important; }
    @page { margin: 10mm; }
}
.label-card {
    max-width: 320px;
    border: 1px solid #ccc;
    border-radius: 8px;
    padding: 20px;
    font-family: monospace;
}
</style>
@endpush

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <a href="{{ route('admin.devices.show', $device) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <button class="btn btn-primary btn-sm print-btn" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Print Label
        </button>
    </div>

    <div class="label-card mx-auto shadow-sm">
        {{-- Company Name --}}
        <div class="text-center fw-bold fs-6 mb-2">
            {{ config('app.name', 'SG NOC') }}
        </div>

        {{-- Asset Code --}}
        <div class="text-center fs-4 fw-bold font-monospace text-primary mb-3">
            {{ $device->asset_code ?? 'NO-CODE' }}
        </div>

        {{-- QR Code --}}
        <div class="text-center mb-3">
            <canvas id="qrCanvas"></canvas>
        </div>

        {{-- Device Info --}}
        <table class="table table-sm table-borderless mb-0" style="font-size:0.8rem">
            <tr><td class="text-muted">Name</td><td class="fw-semibold">{{ $device->name }}</td></tr>
            <tr><td class="text-muted">Type</td><td>{{ $device->typeLabel() }}</td></tr>
            @if($device->serial_number)
            <tr><td class="text-muted">Serial</td><td class="font-monospace">{{ $device->serial_number }}</td></tr>
            @endif
            @if($device->branch)
            <tr><td class="text-muted">Branch</td><td>{{ $device->branch->name }}</td></tr>
            @endif
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
<script>
QRCode.toCanvas(document.getElementById('qrCanvas'), '{{ addslashes($device->asset_code ?? url('/admin/devices/'.$device->id)) }}', {
    width: 160,
    margin: 2,
}, function(err) {
    if (err) console.error('QR error:', err);
});
</script>
@endpush
