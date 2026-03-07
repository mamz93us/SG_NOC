<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $settings->first()->company_name ?? 'Company' }} Directory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            background: linear-gradient(to bottom, #fff5f5 0%, #ffe8e8 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
        }
        
        .header-section {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            padding: 35px 0;
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
            margin-bottom: 35px;
        }
        
        .company-logo {
            max-height: 80px;
            margin-bottom: 15px;
            background: white;
            padding: 10px;
            border-radius: 10px;
        }
        
        .company-title {
            font-size: 34px;
            font-weight: 700;
            color: white;
            margin: 0 0 5px 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .company-subtitle {
            color: rgba(255,255,255,0.95);
            font-size: 16px;
        }
        
        .search-bar {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .search-input {
            border: 2px solid #ffe0e0;
            border-radius: 10px;
            padding: 14px 20px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        .search-input:focus {
            border-color: #e74c3c;
            box-shadow: 0 0 0 4px rgba(231, 76, 60, 0.1);
        }
        
        .btn-search {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            border: none;
            border-radius: 10px;
            padding: 14px 28px;
            color: white;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-search:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.4);
            color: white;
        }
        
        .btn-clear {
            background: #95a5a6;
            border: none;
            border-radius: 10px;
            padding: 14px 22px;
            color: white;
            font-weight: 500;
        }
        
        .btn-clear:hover {
            background: #7f8c8d;
            color: white;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
            border: none;
            border-radius: 10px;
            padding: 14px 24px;
            color: white;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(243, 156, 18, 0.4);
            color: white;
        }
        
        .contact-card {
            background: white;
            border-radius: 12px;
            padding: 22px;
            height: 100%;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border-left: 5px solid #e74c3c;
            position: relative;
            overflow: hidden;
        }
        
        .contact-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.05) 0%, rgba(192, 57, 43, 0.1) 100%);
            border-radius: 0 0 0 100%;
        }
        
        .contact-card:hover {
            box-shadow: 0 8px 25px rgba(231, 76, 60, 0.2);
            transform: translateY(-5px);
        }
        
        .contact-name {
            font-size: 19px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .job-title {
            font-size: 14px;
            color: #e74c3c;
            font-weight: 500;
            font-style: italic;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ffe0e0;
        }
        
        .contact-info {
            font-size: 14px;
            color: #555;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }
        
        .contact-info strong {
            display: inline-block;
            width: 65px;
            color: #e74c3c;
            font-weight: 600;
        }
        
        .contact-info a {
            color: #34495e;
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .contact-info a:hover {
            color: #e74c3c;
        }
        
        .branch-tag {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .branch-tag.red { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; }
        .branch-tag.orange { background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); color: white; }
        .branch-tag.yellow { background: linear-gradient(135deg, #f1c40f 0%, #f39c12 100%); color: white; }
        .branch-tag.coral { background: linear-gradient(135deg, #ff7675 0%, #d63031 100%); color: white; }
        .branch-tag.pink { background: linear-gradient(135deg, #fd79a8 0%, #e84393 100%); color: white; }
        .branch-tag.purple { background: linear-gradient(135deg, #a29bfe 0%, #6c5ce7 100%); color: white; }
        .branch-tag.blue { background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%); color: white; }
        .branch-tag.teal { background: linear-gradient(135deg, #00cec9 0%, #00b894 100%); color: white; }
        
        .pagination .page-link {
            color: #e74c3c;
            border: 2px solid #ffe0e0;
            border-radius: 8px;
            margin: 0 4px;
            padding: 10px 16px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .pagination .page-link:hover {
            background: #e74c3c;
            border-color: #e74c3c;
            color: white;
            transform: translateY(-2px);
        }
        
        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            border-color: #e74c3c;
        }
        
        .no-results {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .no-results h4 {
            color: #e74c3c;
            font-weight: 700;
        }
        
        .loading-spinner {
            text-align: center;
            padding: 40px;
            color: #e74c3c;
            display: none;
        }
        
        .loading-spinner.active {
            display: block;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header-section">
        <div class="container text-center">
            @if($settings->first() && $settings->first()->company_logo)
                <img 
                    src="{{ asset('storage/' . $settings->first()->company_logo) }}" 
                    alt="{{ $settings->first()->company_name ?? 'Company' }} Logo" 
                    class="company-logo"
                >
            @endif
            <h1 class="company-title">
                {{ $settings->first()->company_name ?? 'Company' }} Directory
            </h1>
            <p class="company-subtitle">📱 Employee Contact Information</p>
        </div>
    </div>

    <div class="container">
        <!-- Search Bar -->
        <div class="search-bar">
            <div class="row g-3 align-items-center">
                <div class="col-md-7">
                    <input 
                        type="text" 
                        id="searchInput"
                        class="form-control search-input" 
                        placeholder="🔍 Type to search..." 
                        value="{{ request('search') }}"
                    >
                </div>
                <div class="col-md-5 text-end">
                    <button class="btn btn-clear me-2" id="clearBtn" style="display: {{ request('search') ? 'inline-block' : 'none' }}">Clear</button>
                    <a href="{{ route('public.contacts.print') }}" class="btn btn-print" target="_blank">
    🖨️ Print
</a>
<a href="{{ route('public.contacts.print.compact') }}" class="btn btn-print" target="_blank" style="background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);">
    📄 Compact
</a>

                </div>
            </div>
        </div>

        <!-- Loading Spinner -->
        <div class="loading-spinner" id="loadingSpinner">
            <div class="spinner-border text-danger" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Searching...</p>
        </div>

        <!-- Contacts Grid -->
        <div id="contactsContainer">
            @if($contacts->count() > 0)
                <div class="row g-4 mb-5">
                    @foreach($contacts as $contact)
                        @php
                            $colors = ['red', 'orange', 'yellow', 'coral', 'pink', 'purple', 'blue', 'teal'];
                            $branchColor = $colors[($contact->branch_id ?? 0) % count($colors)];
                        @endphp
                        <div class="col-md-6 col-lg-4">
                            <div class="contact-card">
                                <div class="contact-name">
                                    {{ $contact->first_name }} {{ $contact->last_name }}
                                </div>
                                
                                @if($contact->job_title)
                                    <div class="job-title">
                                        💼 {{ $contact->job_title }}
                                    </div>
                                @endif
                                
                                <div class="contact-info">
                                    <strong>Phone:</strong>
                                    <a href="tel:{{ $contact->phone }}">{{ $contact->phone }}</a>
                                </div>
                                
                                @if($contact->email)
                                    <div class="contact-info">
                                        <strong>Email:</strong>
                                        <a href="mailto:{{ $contact->email }}">{{ Str::limit($contact->email, 30) }}</a>
                                    </div>
                                @endif
                                
                                @if($contact->branch)
                                    <span class="branch-tag {{ $branchColor }}">
                                        🏢 {{ $contact->branch->name }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                
                @if($contacts->hasPages())
                    <div class="d-flex justify-content-center mb-5">
                        {{ $contacts->withQueryString()->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            @else
                <div class="no-results">
                    <h4>No contacts found</h4>
                    <p class="text-muted">Try adjusting your search</p>
                </div>
            @endif
        </div>
    </div>

    <script>
        // Live search with debounce
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        const clearBtn = document.getElementById('clearBtn');
        const contactsContainer = document.getElementById('contactsContainer');
        const loadingSpinner = document.getElementById('loadingSpinner');
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            // Show/hide clear button
            clearBtn.style.display = query ? 'inline-block' : 'none';
            
            // Debounce: wait 500ms after user stops typing
            searchTimeout = setTimeout(() => {
                performSearch(query);
            }, 500);
        });
        
        clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            clearBtn.style.display = 'none';
            performSearch('');
        });
        
        function performSearch(query) {
            // Show loading
            loadingSpinner.classList.add('active');
            contactsContainer.style.opacity = '0.5';
            
            // Fetch results
            const url = new URL(window.location.href);
            url.searchParams.set('search', query);
            
            fetch(url)
                .then(response => response.text())
                .then(html => {
                    // Parse the response
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newContainer = doc.getElementById('contactsContainer');
                    
                    // Update content
                    contactsContainer.innerHTML = newContainer.innerHTML;
                    
                    // Update URL without reload
                    if (query) {
                        window.history.pushState({}, '', url);
                    } else {
                        window.history.pushState({}, '', '/contacts');
                    }
                    
                    // Hide loading
                    loadingSpinner.classList.remove('active');
                    contactsContainer.style.opacity = '1';
                })
                .catch(error => {
                    console.error('Search error:', error);
                    loadingSpinner.classList.remove('active');
                    contactsContainer.style.opacity = '1';
                });
        }
    </script>
</body>
</html>
