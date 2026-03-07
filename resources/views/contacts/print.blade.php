<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $settings->company_name ?? 'Company' }} - Directory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            background: white; 
            font-size: 12px;
        }
        .header-section {
            margin-bottom: 20px;
            border-bottom: 3px solid #333;
            padding-bottom: 10px;
        }
        .no-print {
            margin-bottom: 20px;
        }
        table {
            font-size: 11px;
        }
        th {
            background: #f0f0f0 !important;
            font-weight: bold;
            border: 1px solid #333 !important;
        }
        td {
            border: 1px solid #ddd !important;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
                padding: 15px;
            }
            table {
                page-break-inside: auto;
            }
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            thead {
                display: table-header-group;
            }
            @page {
                margin: 1cm;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        
        <!-- Header -->
        <div class="header-section">
            <div class="row align-items-center">
                <div class="col-8">
                    @if($settings && $settings->company_logo)
                        <img src="{{ asset('storage/' . $settings->company_logo) }}" alt="Logo" style="max-height: 60px; margin-bottom: 10px;">
                    @endif
                    <h2 class="mb-0">{{ $settings->company_name ?? 'Company Directory' }}</h2>
                    <p class="mb-0 text-muted">
                        @if($selectedBranch)
                            {{ $selectedBranch->name }} Branch
                        @else
                            All Contacts
                        @endif
                        - Total: {{ $contacts->count() }}
                    </p>
                </div>
                <div class="col-4 text-end">
                    <p class="mb-0 text-muted">
                        <strong>Printed:</strong> {{ now()->format('d M Y, h:i A') }}
                    </p>
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
        🖨️ Print Directory
    </button>
    <a href="{{ route('public.contacts.print.compact') }}" class="btn btn-info">Compact Layout</a>
    <a href="/contacts" class="btn btn-secondary">← Back</a>
                </div>
            </div>
        </div>
		<div class="col-md-6 text-end">
   
</div>


        <!-- Contacts Table -->
        @if($contacts->count() > 0)
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 35%;">Name</th>
                        <th style="width: 20%;">Phone</th>
                        <th style="width: 25%;">Email</th>
                        <th style="width: 15%;">Branch</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($contacts as $index => $contact)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $contact->first_name }} {{ $contact->last_name }}</td>
                            <td>{{ $contact->phone }}</td>
                            <td>{{ $contact->email }}</td>
                            <td>{{ $contact->branch->name ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="alert alert-info">No contacts found.</div>
        @endif

    </div>
</body>
</html>
