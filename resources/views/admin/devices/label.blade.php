<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Asset Label — {{ $device->asset_code }}</title>
    <style>
        /* Base styles for screen */
        body {
            margin: 0;
            padding: 0;
            background: #f4f4f4;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        
        .no-print-tools {
            margin-bottom: 20px;
            text-align: center;
        }
        
        .btn {
            display: inline-block;
            padding: 8px 16px;
            background: #0d6efd;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            border: none;
            cursor: pointer;
        }
        .btn-secondary { background: #6c757d; }

        /* THE LABEL BOX */
        .asset-label {
            width: 2in;
            height: 1in;
            background: white;
            padding: 0.1in;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            position: relative;
        }

        /* PRINT SETTINGS */
        @media print {
            @page {
                size: 2in 1in landscape;
                margin: 0; /* Removes browser header/footer */
            }
            body { 
                background: white; 
                min-height: auto;
            }
            .no-print-tools { display: none; }
            .asset-label {
                box-shadow: none;
                margin: 0;
                position: absolute;
                top: 0;
                left: 0;
            }
        }

        /* Content Layout */
        .qr-side {
            flex: 0 0 0.8in;
        }
        .qr-side canvas {
            display: block;
            width: 0.82in !important;
            height: 0.82in !important;
        }
        .info-side {
            flex: 1;
            padding-left: 0.08in;
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow: hidden;
            color: black;
        }
        .company {
            font-size: 7.5pt;
            font-weight: 900;
            text-transform: uppercase;
            border-bottom: 1px solid black;
            padding-bottom: 1px;
            margin-bottom: 2px;
            white-space: nowrap;
        }
        .device-name {
            font-size: 7pt;
            font-weight: normal;
            line-height: 1.1;
            margin-bottom: 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .asset-code {
            font-size: 10.5pt;
            font-weight: bold;
            font-family: "Courier New", Courier, monospace;
            letter-spacing: -0.2pt;
        }
    </style>
</head>
<body>

    <div class="no-print-tools">
        <div style="margin-bottom: 10px; font-weight: bold; color: #444;">Asset Label Preview</div>
        <button class="btn" onclick="window.print()">Print Label</button>
        <a href="{{ route('admin.devices.show', $device) }}" class="btn btn-secondary">Back to Portal</a>
        <div style="margin-top: 10px; font-size: 12px; color: #888;">Printer should be set to 2" x 1" Landscape</div>
    </div>

    <!-- The 2x1 Label -->
    <div class="asset-label">
        <div class="qr-side">
            <canvas id="qrCanvas"></canvas>
        </div>
        <div class="info-side">
            <div class="company">SAMIR GROUP</div>
            <div class="device-name">{{ $device->name }}</div>
            <div class="asset-code">{{ $device->asset_code }}</div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
    <script>
        QRCode.toCanvas(document.getElementById('qrCanvas'), '{{ $device->asset_code }}', {
            width: 100,
            margin: 0,
            errorCorrectionLevel: 'H'
        }, function(err) {
            if (err) console.error(err);
        });
        
        // Auto-trigger print if requested? (Optional, skipping for now to let user preview)
    </script>
</body>
</html>
