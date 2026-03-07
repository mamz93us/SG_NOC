<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $settings->company_name ?? 'Company' }} - Contact List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            background: white; 
            font-size: 10px;
            font-family: Arial, sans-serif;
        }
        
        .header-section {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #333;
        }
        
        .company-logo {
            max-height: 50px;
        }
        
        .company-name {
            font-size: 20px;
            font-weight: bold;
            margin: 5px 0;
        }
        
        .no-print {
            margin-bottom: 20px;
        }
        
        /* 6-column grid layout */
        .contacts-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 6px;
            margin-top: 15px;
        }
        
        .contact-item {
            border: 1px solid #ddd;
            padding: 6px 8px;
            background: #f9f9f9;
            border-radius: 3px;
            page-break-inside: avoid;
            font-size: 9px;
            line-height: 1.3;
        }
        
        .contact-name {
            font-weight: bold;
            color: #333;
            display: inline;
        }
        
        .contact-phone {
            color: #666;
            display: inline;
            margin-left: 4px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                margin: 0;
                padding: 8px;
            }
            
            @page {
                size: landscape;
                margin: 0.8cm;
            }
            
            .contact-item {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        
        <!-- Header -->
        <div class="header-section">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    @if($settings && $settings->company_logo)
                        <img src="{{ asset('storage/' . $settings->company_logo) }}" alt="Logo" class="company-logo">
                    @endif
                    <div class="company-name">{{ $settings->company_name ?? 'Company Directory' }}</div>
                    <div style="font-size: 12px; color: #666;">
                        @if($selectedBranch)
                            {{ $selectedBranch->name }} Branch - 
                        @endif
                        Total Contacts: {{ $contacts->count() }}
                    </div>
                </div>
                <div class="text-end" style="font-size: 11px; color: #666;">
                    <strong>Printed:</strong> {{ now()->format('d M Y, h:i A') }}
                </div>
            </div>
        </div>

        <!-- Filter & Print Buttons -->
        <div class="no-print">
            <div class="row mb-3">
                <div class="col-md-6">
                    <form method="GET" class="d-flex gap-2">
                        <select name="branch_id" class="form-select">
                            <option value="">All Branches</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-primary">Filter</button>
                    </form>
                </div>
                <div class="col-md-6 text-end">
                    <button onclick="window.print()" class="btn btn-success">
                        🖨️ Print
                    </button>
                    <a href="{{ route('public.contacts.print') }}" class="btn btn-secondary">Full Layout</a>
                    <a href="/contacts" class="btn btn-secondary">← Back</a>
                </div>
            </div>
        </div>

        <!-- Contacts Grid (6 Columns) -->
        @if($contacts->count() > 0)
            <div class="contacts-grid">
                @foreach($contacts as $contact)
                    <div class="contact-item">
                        <span class="contact-name">{{ $contact->first_name }} {{ $contact->last_name }}</span>
                        <span class="contact-phone">📞 {{ $contact->phone }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <div class="alert alert-info">No contacts found.</div>
        @endif

    </div>
</body>
</html>
