@extends('layouts.admin')
@section('title', 'Asset Label — ' . $device->asset_code)

@push('styles')
<style>
@media print {
    nav, .navbar, .no-print, .btn, .breadcrumb { display: none !important; }
    body, .main-content { background: white !important; margin: 0 !important; padding: 0 !important; }
    .container { max-width: 100% !important; margin: 0 !important; padding: 0 !important; width: auto !important; }
    .label-container { box-shadow: none !important; border: none !important; margin: 0 !important; padding: 0 !important; }
    @page { 
        size: 2in 1in; 
        margin: 0; 
    }
}

.label-container {
    padding: 100px 0; /* Add space on screen, removed during print */
}

.asset-label {
    width: 2in;
    height: 1in;
    background: white;
    border: 1px dashed #ccc;
    display: flex;
    align-items: center;
    padding: 0.1in;
    box-sizing: border-box;
    overflow: hidden;
    margin: 0 auto;
    color: #000;
}

@media print {
    .asset-label { border: none; margin: 0; }
}

.qr-side {
    flex: 0 0 0.8in;
}
.qr-side canvas {
    display: block;
    width: 0.8in !important;
    height: 0.8in !important;
}

.info-side {
    flex: 1;
    padding-left: 0.1in;
    display: flex;
    flex-direction: column;
    justify-content: center;
    overflow: hidden;
}

.company-title {
    font-size: 7pt;
    font-weight: 800;
    text-transform: uppercase;
    border-bottom: 0.5pt solid #000;
    margin-bottom: 1pt;
    white-space: nowrap;
}

.asset-n {
    font-size: 7pt;
    font-weight: normal;
    line-height: 1.1;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.asset-c {
    font-size: 10pt;
    font-weight: bold;
    font-family: 'Courier New', Courier, monospace;
    margin-top: auto;
}
</style>
@endpush

@section('content')
<div class="container label-container">
    <div class="d-flex justify-content-center mb-4 no-print gap-2">
        <a href="{{ route('admin.devices.show', $device) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back to Device
        </a>
        <button class="btn btn-primary btn-sm" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Label (1x2 inch)
        </button>
    </div>

    <div class="asset-label shadow-sm">
        <div class="qr-side">
            <canvas id="qrCanvas"></canvas>
        </div>
        <div class="info-side">
            <div class="company-title">SAMIR GROUP</div>
            <div class="asset-n">{{ $device->name }}</div>
            <div class="asset-c">{{ $device->asset_code }}</div>
        </div>
    </div>
    
    <div class="text-center mt-3 no-print text-muted small">
        <i class="bi bi-info-circle me-1"></i> Preview: Actual size is 2" wide x 1" high.
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
<script>
QRCode.toCanvas(document.getElementById('qrCanvas'), '{{ $device->asset_code }}', {
    width: 100,
    margin: 0,
    errorCorrectionLevel: 'H'
}, function(err) {
    if (err) console.error(err);
});
</script>
@endpush
