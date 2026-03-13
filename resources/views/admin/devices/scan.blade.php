@extends('layouts.admin')
@section('title', 'QR Scanner')

@section('content')
<div class="container py-4" style="max-width:600px">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-qr-code-scan me-2"></i>Asset QR Scanner</h4>
        <a href="{{ route('admin.itam.dashboard') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    {{-- Camera Scanner --}}
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold">Camera Scanner</div>
        <div class="card-body text-center">
            <div id="scannerStatus" class="alert alert-info mb-3">
                <i class="bi bi-camera me-1"></i>Click "Start Camera" to begin scanning
            </div>
            <div class="position-relative d-inline-block mb-3">
                <video id="cameraFeed" style="max-width:100%;border-radius:8px;display:none" autoplay muted playsinline></video>
                <canvas id="scanCanvas" style="display:none"></canvas>
            </div>
            <div>
                <button id="startBtn" class="btn btn-primary" onclick="startCamera()">
                    <i class="bi bi-camera-fill me-1"></i>Start Camera
                </button>
                <button id="stopBtn" class="btn btn-outline-secondary ms-2" onclick="stopCamera()" style="display:none">
                    <i class="bi bi-stop-circle me-1"></i>Stop
                </button>
            </div>
        </div>
    </div>

    {{-- Manual Lookup --}}
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold">Manual Asset Code Lookup</div>
        <div class="card-body">
            <form onsubmit="lookupCode(event)">
                <div class="input-group">
                    <input type="text" id="manualCode" class="form-control font-monospace" placeholder="Enter asset code (e.g. SG-LAP-000001)" autofocus>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Look Up</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Result --}}
    <div id="resultCard" class="card shadow-sm" style="display:none">
        <div class="card-header bg-success text-white fw-semibold"><i class="bi bi-check-circle me-1"></i>Device Found</div>
        <div class="card-body" id="resultBody"></div>
    </div>
    <div id="notFoundCard" class="alert alert-warning mt-3" style="display:none">
        <i class="bi bi-exclamation-triangle me-1"></i>No device found with that asset code.
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script>
let stream = null;
let scanning = false;
let animFrame = null;

async function startCamera() {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        const video = document.getElementById('cameraFeed');
        video.srcObject = stream;
        video.style.display = '';
        document.getElementById('startBtn').style.display = 'none';
        document.getElementById('stopBtn').style.display = '';
        document.getElementById('scannerStatus').innerHTML = '<i class="bi bi-camera-video me-1"></i>Scanning... point camera at QR code';
        document.getElementById('scannerStatus').className = 'alert alert-success mb-3';
        scanning = true;
        scanFrame();
    } catch(e) {
        document.getElementById('scannerStatus').innerHTML = '<i class="bi bi-x-circle me-1"></i>Camera access denied. Use manual lookup below.';
        document.getElementById('scannerStatus').className = 'alert alert-danger mb-3';
    }
}

function stopCamera() {
    scanning = false;
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    if (animFrame) { cancelAnimationFrame(animFrame); animFrame = null; }
    document.getElementById('cameraFeed').style.display = 'none';
    document.getElementById('startBtn').style.display = '';
    document.getElementById('stopBtn').style.display = 'none';
    document.getElementById('scannerStatus').innerHTML = '<i class="bi bi-camera me-1"></i>Click "Start Camera" to begin scanning';
    document.getElementById('scannerStatus').className = 'alert alert-info mb-3';
}

function scanFrame() {
    if (!scanning) return;
    const video = document.getElementById('cameraFeed');
    if (video.readyState === video.HAVE_ENOUGH_DATA) {
        const canvas = document.getElementById('scanCanvas');
        canvas.height = video.videoHeight;
        canvas.width = video.videoWidth;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const code = jsQR(imageData.data, imageData.width, imageData.height);
        if (code) {
            stopCamera();
            lookupAssetCode(code.data);
            return;
        }
    }
    animFrame = requestAnimationFrame(scanFrame);
}

function lookupCode(e) {
    e.preventDefault();
    const code = document.getElementById('manualCode').value.trim();
    if (code) lookupAssetCode(code);
}

async function lookupAssetCode(code) {
    document.getElementById('resultCard').style.display = 'none';
    document.getElementById('notFoundCard').style.display = 'none';
    try {
        const resp = await fetch('/admin/devices?asset_code=' + encodeURIComponent(code) + '&format=json', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await resp.json();
        if (data.device) {
            const d = data.device;
            document.getElementById('resultBody').innerHTML = `
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="mb-1">${d.name}</h5>
                        <span class="badge bg-secondary">${d.type_label || d.type}</span>
                        ${d.asset_code ? `<span class="badge bg-primary ms-1">${d.asset_code}</span>` : ''}
                    </div>
                    <a href="/admin/devices/${d.id}" class="btn btn-sm btn-outline-primary">View Device</a>
                </div>
                <hr>
                <div class="row g-2 small mt-1">
                    ${d.serial_number ? `<div class="col-6"><span class="text-muted">Serial:</span> <span class="font-monospace">${d.serial_number}</span></div>` : ''}
                    ${d.branch_name ? `<div class="col-6"><span class="text-muted">Branch:</span> ${d.branch_name}</div>` : ''}
                    ${d.status ? `<div class="col-6"><span class="text-muted">Status:</span> ${d.status}</div>` : ''}
                    ${d.assigned_to ? `<div class="col-6"><span class="text-muted">Assigned:</span> ${d.assigned_to}</div>` : ''}
                </div>
            `;
            document.getElementById('resultCard').style.display = '';
        } else {
            document.getElementById('notFoundCard').style.display = '';
        }
    } catch(e) {
        document.getElementById('notFoundCard').textContent = 'Error looking up asset code.';
        document.getElementById('notFoundCard').style.display = '';
    }
}
</script>
@endpush
