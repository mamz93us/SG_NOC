<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'Company Directory') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
        }
        
        .welcome-container {
            max-width: 800px;
            width: 100%;
        }
        
        .welcome-card {
            background: white;
            border-radius: 30px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: fadeInUp 0.8s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .welcome-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 60px 40px;
            text-align: center;
            color: white;
        }
        
        .company-logo {
            max-width: 180px;
            max-height: 120px;
            margin-bottom: 20px;
            background: white;
            padding: 20px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .welcome-title {
            font-size: 42px;
            font-weight: 700;
            margin: 0;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .welcome-subtitle {
            font-size: 18px;
            margin: 15px 0 0 0;
            opacity: 0.95;
        }
        
        .welcome-body {
            padding: 50px 40px;
        }
        
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        
        .action-card {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border-radius: 20px;
            padding: 35px 30px;
            text-align: center;
            transition: all 0.3s;
            border: none;
            text-decoration: none;
            color: #333;
            display: block;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .action-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            color: #333;
        }
        
        .action-card.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .action-card.primary:hover {
            color: white;
        }
        
        .action-card.success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        
        .action-card.success:hover {
            color: white;
        }
        
        .action-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .action-title {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .action-description {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }
        
        .admin-link {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #f0f0f0;
            text-align: center;
        }
        
        .admin-btn {
            display: inline-block;
            padding: 12px 30px;
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .admin-btn:hover {
            background: #667eea;
            color: white;
        }
        
        .footer-text {
            text-align: center;
            color: white;
            margin-top: 30px;
            font-size: 14px;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="welcome-container">
        <div class="welcome-card">
            @php
                $settings = App\Models\Setting::first();
            @endphp
            
            <!-- Header -->
            <div class="welcome-header">
                @if($settings && $settings->company_logo)
                    <img 
                        src="{{ asset('storage/' . $settings->company_logo) }}" 
                        alt="{{ $settings->company_name ?? 'Company' }} Logo" 
                        class="company-logo"
                    >
                @else
                    <div style="font-size: 72px; margin-bottom: 20px;">📱</div>
                @endif
                
                <h1 class="welcome-title">
                    {{ $settings->company_name ?? 'Company Directory' }}
                </h1>
                <p class="welcome-subtitle">
                    Your Complete Employee Contact Directory
                </p>
            </div>
            
            <!-- Body -->
            <div class="welcome-body">
                <div class="action-grid">
                    <!-- View Contacts -->
                    <a href="/contacts" class="action-card primary">
                        <div class="action-icon">👥</div>
                        <h3 class="action-title">Browse Directory</h3>
                        <p class="action-description">
                            Search and view all employee contacts
                        </p>
                    </a>
                    
                    <!-- Print Directory -->
                    <a href="/contacts/print" target="_blank" class="action-card success">
                        <div class="action-icon">🖨️</div>
                        <h3 class="action-title">Print Directory</h3>
                        <p class="action-description">
                            Print or save contacts as PDF
                        </p>
                    </a>
                </div>
                
                <!-- Admin Login -->
                <div class="admin-link">
                    <a href="/login" class="admin-btn">
                        🔐 Admin Login
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer-text">
            <p>&copy; {{ date('Y') }} {{ $settings->company_name ?? 'Company' }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
