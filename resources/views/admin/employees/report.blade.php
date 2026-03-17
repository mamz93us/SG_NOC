<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Report - {{ $employee->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            color: #222;
            background: #f5f5f5;
            line-height: 1.5;
        }

        .report-page {
            max-width: 210mm;
            margin: 20px auto;
            background: #fff;
            padding: 30px 35px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        /* ── Header ────────────────────────────────── */
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid #0d6efd;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .report-header .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .report-header .logo-section img {
            max-height: 55px;
            max-width: 180px;
            object-fit: contain;
        }
        .report-header .company-name {
            font-size: 18px;
            font-weight: 700;
            color: #0d6efd;
        }
        .report-header .report-title {
            text-align: right;
        }
        .report-header .report-title h1 {
            font-size: 16px;
            font-weight: 700;
            color: #333;
            margin-bottom: 3px;
        }
        .report-header .report-title .date {
            font-size: 11px;
            color: #666;
        }

        /* ── Sections ──────────────────────────────── */
        .section {
            margin-bottom: 20px;
        }
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: #0d6efd;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 4px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ── Employee Info Grid ────────────────────── */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 30px;
            margin-bottom: 15px;
        }
        .info-grid .info-item {
            display: flex;
            gap: 8px;
            padding: 3px 0;
            border-bottom: 1px dotted #eee;
        }
        .info-grid .info-label {
            font-weight: 600;
            color: #555;
            min-width: 100px;
            flex-shrink: 0;
        }
        .info-grid .info-value {
            color: #222;
        }

        /* ── Status Badge ──────────────────────────── */
        .status-badge {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-active { background: #d1e7dd; color: #0f5132; }
        .status-terminated { background: #f8d7da; color: #842029; }
        .status-on_leave { background: #fff3cd; color: #664d03; }

        /* ── Tables ────────────────────────────────── */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
            font-size: 11px;
        }
        table th {
            background: #f0f4f8;
            color: #333;
            font-weight: 600;
            text-align: left;
            padding: 6px 8px;
            border: 1px solid #dee2e6;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table td {
            padding: 5px 8px;
            border: 1px solid #dee2e6;
            vertical-align: top;
        }
        table tbody tr:nth-child(even) {
            background: #fafbfc;
        }
        .no-data {
            text-align: center;
            color: #999;
            font-style: italic;
            padding: 12px;
        }
        .badge-sm {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 8px;
            font-size: 9px;
            font-weight: 600;
        }
        .badge-good { background: #d1e7dd; color: #0f5132; }
        .badge-fair { background: #fff3cd; color: #664d03; }
        .badge-poor { background: #f8d7da; color: #842029; }

        /* ── Signature Section ─────────────────────── */
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 50px;
            margin-top: 40px;
            padding-top: 20px;
        }
        .signature-block {
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 50px;
            padding-top: 5px;
            font-size: 11px;
            font-weight: 600;
            color: #555;
        }
        .signature-date {
            font-size: 10px;
            color: #888;
            margin-top: 3px;
        }

        /* ── Footer ────────────────────────────────── */
        .report-footer {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 10px;
            color: #999;
        }

        /* ── Print Button ──────────────────────────── */
        .no-print-tools {
            text-align: center;
            margin-bottom: 15px;
        }
        .no-print-tools button {
            background: #0d6efd;
            color: #fff;
            border: none;
            padding: 10px 30px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .no-print-tools button:hover {
            background: #0b5ed7;
        }
        .no-print-tools a {
            color: #666;
            text-decoration: none;
            margin-left: 15px;
            font-size: 13px;
        }

        /* ── Print Media ───────────────────────────── */
        @media print {
            body { background: #fff; }
            .report-page {
                margin: 0;
                padding: 15px 20px;
                box-shadow: none;
                max-width: 100%;
            }
            .no-print-tools { display: none !important; }
            @page {
                size: A4 portrait;
                margin: 12mm 10mm;
            }
            .section { page-break-inside: avoid; }
            .signatures { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="no-print-tools">
    <button onclick="window.print()"><span style="margin-right:6px">&#128424;</span> Print Report</button>
    <a href="{{ route('admin.employees.show', $employee) }}">&#8592; Back to Profile</a>
</div>

<div class="report-page">

    {{-- ── HEADER ──────────────────────────────────────── --}}
    <div class="report-header">
        <div class="logo-section">
            @if($settings && $settings->company_logo)
                <img src="{{ asset('storage/' . $settings->company_logo) }}" alt="Logo">
            @endif
            <span class="company-name">{{ $settings->company_name ?? 'Company' }}</span>
        </div>
        <div class="report-title">
            <h1>Employee Asset Report</h1>
            <div class="date">Generated: {{ now()->format('d M Y, h:i A') }}</div>
        </div>
    </div>

    {{-- ── EMPLOYEE INFO ───────────────────────────────── --}}
    <div class="section">
        <div class="section-title">Employee Information</div>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Full Name</span>
                <span class="info-value">{{ $employee->name }}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Status</span>
                <span class="info-value">
                    <span class="status-badge status-{{ $employee->status }}">{{ ucfirst(str_replace('_', ' ', $employee->status)) }}</span>
                </span>
            </div>
            <div class="info-item">
                <span class="info-label">Email</span>
                <span class="info-value">{{ $employee->email ?? '—' }}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Job Title</span>
                <span class="info-value">{{ $employee->job_title ?? '—' }}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Branch</span>
                <span class="info-value">{{ $employee->branch?->name ?? '—' }}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Department</span>
                <span class="info-value">{{ $employee->department?->name ?? '—' }}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Manager</span>
                <span class="info-value">{{ $employee->manager?->name ?? '—' }}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Hired Date</span>
                <span class="info-value">{{ $employee->hired_date ? \Carbon\Carbon::parse($employee->hired_date)->format('d M Y') : '—' }}</span>
            </div>
        </div>
    </div>

    {{-- ── IT ASSETS (Devices) ─────────────────────────── --}}
    <div class="section">
        <div class="section-title">IT Assets (Devices)</div>
        @php
            $activeAssets = $employee->assetAssignments->whereNull('returned_date');
        @endphp
        @if($activeAssets->count() > 0)
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Device Name</th>
                    <th>Type</th>
                    <th>Serial Number</th>
                    <th>Asset Code</th>
                    <th>Model</th>
                    <th>Condition</th>
                    <th>Assigned Date</th>
                </tr>
            </thead>
            <tbody>
                @foreach($activeAssets as $i => $ea)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><strong>{{ $ea->device?->name ?? '—' }}</strong></td>
                    <td>{{ ucfirst($ea->device?->type ?? '—') }}</td>
                    <td style="font-family:monospace;font-size:10px">{{ $ea->device?->serial_number ?? '—' }}</td>
                    <td style="font-family:monospace;font-size:10px">{{ $ea->device?->asset_code ?? '—' }}</td>
                    <td>{{ $ea->device?->model ?? '—' }}</td>
                    <td>
                        @if($ea->condition)
                        <span class="badge-sm badge-{{ $ea->condition }}">{{ ucfirst($ea->condition) }}</span>
                        @else — @endif
                    </td>
                    <td>{{ $ea->assigned_date?->format('d M Y') ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="no-data">No IT assets currently assigned.</div>
        @endif
    </div>

    {{-- ── PERSONAL ITEMS ──────────────────────────────── --}}
    <div class="section">
        <div class="section-title">Personal Items</div>
        @php
            $activeItems = $employee->activeItems ?? collect();
        @endphp
        @if($activeItems->count() > 0)
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item Name</th>
                    <th>Type</th>
                    <th>Serial / Model</th>
                    <th>Condition</th>
                    <th>Assigned Date</th>
                </tr>
            </thead>
            <tbody>
                @foreach($activeItems as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><strong>{{ $item->item_name }}</strong></td>
                    <td>{{ ucfirst($item->item_type ?? '—') }}</td>
                    <td style="font-family:monospace;font-size:10px">
                        {{ $item->serial_number ?? '' }}{{ $item->serial_number && $item->model ? ' / ' : '' }}{{ $item->model ?? '' }}
                        @if(!$item->serial_number && !$item->model) — @endif
                    </td>
                    <td>
                        @if($item->condition)
                        <span class="badge-sm badge-{{ $item->condition }}">{{ ucfirst($item->condition) }}</span>
                        @else — @endif
                    </td>
                    <td>{{ $item->assigned_date?->format('d M Y') ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="no-data">No personal items currently assigned.</div>
        @endif
    </div>

    {{-- ── ACCESSORIES ─────────────────────────────────── --}}
    <div class="section">
        <div class="section-title">Accessories</div>
        @php
            $activeAccessories = $employee->accessoryAssignments->whereNull('returned_date');
        @endphp
        @if($activeAccessories->count() > 0)
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Accessory Name</th>
                    <th>Category</th>
                    <th>Assigned Date</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($activeAccessories as $i => $aa)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><strong>{{ $aa->accessory?->name ?? '—' }}</strong></td>
                    <td>{{ ucfirst($aa->accessory?->category ?? '—') }}</td>
                    <td>{{ $aa->assigned_date?->format('d M Y') ?? '—' }}</td>
                    <td class="text-muted">{{ $aa->notes ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="no-data">No accessories currently assigned.</div>
        @endif
    </div>

    {{-- ── SOFTWARE LICENSES ───────────────────────────── --}}
    <div class="section">
        <div class="section-title">Software Licenses</div>
        @if($licenseAssignments->count() > 0)
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>License Name</th>
                    <th>Vendor</th>
                    <th>Type</th>
                    <th>Assigned Date</th>
                    <th>Expiry</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($licenseAssignments as $i => $la)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><strong>{{ $la->license?->license_name ?? '—' }}</strong></td>
                    <td>{{ $la->license?->vendor ?? '—' }}</td>
                    <td>{{ ucfirst($la->license?->license_type ?? '—') }}</td>
                    <td>{{ $la->assigned_date?->format('d M Y') ?? '—' }}</td>
                    <td>
                        @if($la->license?->expiry_date)
                            {{ $la->license->expiry_date->format('d M Y') }}
                        @else — @endif
                    </td>
                    <td>{{ $la->notes ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="no-data">No software licenses currently assigned.</div>
        @endif
    </div>

    {{-- ── SUMMARY ─────────────────────────────────────── --}}
    <div class="section" style="background:#f0f4f8;padding:10px 15px;border-radius:6px;margin-top:15px">
        <strong style="font-size:11px;color:#333">Summary:</strong>
        <span style="font-size:11px;color:#555;margin-left:10px">
            {{ $activeAssets->count() }} Device(s) &bull;
            {{ $activeItems->count() }} Personal Item(s) &bull;
            {{ $activeAccessories->count() }} Accessory(ies) &bull;
            {{ $licenseAssignments->count() }} License(s)
        </span>
    </div>

    {{-- ── SIGNATURES ──────────────────────────────────── --}}
    <div class="signatures">
        <div class="signature-block">
            <div class="signature-line">Employee Signature</div>
            <div class="signature-date">Date: _______________</div>
        </div>
        <div class="signature-block">
            <div class="signature-line">IT Department Signature</div>
            <div class="signature-date">Date: _______________</div>
        </div>
    </div>

    {{-- ── FOOTER ──────────────────────────────────────── --}}
    <div class="report-footer">
        {{ $settings->company_name ?? 'Company' }} &mdash; Employee Asset Report &mdash; Generated on {{ now()->format('d M Y, h:i A') }}
    </div>

</div>

</body>
</html>
