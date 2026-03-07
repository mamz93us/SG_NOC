<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Directory</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f4f4f4;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            text-align: center;
        }

        .company-logo {
            max-width: 200px;
            max-height: 80px;
            margin-bottom: 15px;
        }

        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .search-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .search-form {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }

        .form-group input,
        .form-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .btn-print {
            background: #27ae60;
            color: white;
        }

        .btn-print:hover {
            background: #229954;
        }

        .contacts-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #34495e;
            color: white;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }

        tbody tr:hover {
            background: #f8f9fa;
        }

        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 5px;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
        }

        .pagination a:hover {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        .pagination .active span {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        .no-results {
            padding: 40px;
            text-align: center;
            color: #7f8c8d;
        }

        /* Print Styles */
        @media print {
            @page {
                size: A4;
                margin: 1cm;
            }

            body {
                background: white;
            }

            .container {
                max-width: 100%;
                padding: 0;
            }

            .search-box,
            .btn-print,
            .pagination {
                display: none !important;
            }

            .header {
                box-shadow: none;
                border: 1px solid #ddd;
                page-break-after: avoid;
            }

            .contacts-table {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            table {
                font-size: 10pt;
            }

            th, td {
                padding: 8px;
            }

            tbody tr {
                page-break-inside: avoid;
            }

            thead {
                display: table-header-group;
            }

            .company-logo {
                max-height: 60px;
            }
        }

        @media (max-width: 768px) {
            .search-form {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 10px 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header with Logo -->
        <div class="header">
            @if($settings->company_logo)
                <img src="{{ asset('storage/' . $settings->company_logo) }}" alt="Company Logo" class="company-logo">
            @endif
            <h1>{{ $settings->company_name }}</h1>
            <p>Contact Directory</p>
        </div>

        <!-- Search Box (Hidden on Print) -->
        <div class="search-box">
            <form method="GET" action="{{ route('public.contacts') }}" class="search-form">
                <div class="form-group">
                    <label for="search">Search</label>
                    <input 
                        type="text" 
                        id="search" 
                        name="search" 
                        placeholder="Name, phone, or email..."
                        value="{{ request('search') }}"
                    >
                </div>

                <div class="form-group">
                    <label for="branch_id">Filter by Branch</label>
                    <select name="branch_id" id="branch_id">
                        <option value="">All Branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <a href="{{ route('public.contacts') }}" class="btn btn-secondary">Clear</a>
                    <button type="button" onclick="window.print()" class="btn btn-print">Print</button>
                </div>
            </form>
        </div>

        <!-- Contacts Table -->
        <div class="contacts-table">
            @if($contacts->count() > 0)
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Branch</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contacts as $contact)
                            <tr>
                                <td>{{ $contact->first_name }} {{ $contact->last_name }}</td>
                                <td>{{ $contact->phone }}</td>
                                <td>{{ $contact->email }}</td>
                                <td>{{ $contact->branch->name ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <!-- Pagination (Hidden on Print) -->
                <div class="pagination">
                    {{ $contacts->withQueryString()->links() }}
                </div>
            @else
                <div class="no-results">
                    <p>No contacts found.</p>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
