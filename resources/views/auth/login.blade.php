<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – SG NOC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 15px;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        
        .login-logo {
            max-width: 120px;
            max-height: 80px;
            margin-bottom: 15px;
            background: white;
            padding: 10px;
            border-radius: 10px;
        }
        
        .login-title {
            font-size: 24px;
            font-weight: 600;
            margin: 0;
        }
        
        .login-body {
            padding: 40px 30px;
        }
        
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        
        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            padding: 12px;
            font-size: 16px;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }
        
        .forgot-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .forgot-link:hover {
            color: #764ba2;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Header with Logo -->
            <div class="login-header">
                @php
                    $settings = App\Models\Setting::first();
                @endphp
                
                @if($settings && $settings->company_logo)
                    <img 
                        src="{{ asset('storage/' . $settings->company_logo) }}" 
                        alt="{{ $settings->company_name ?? 'Company' }} Logo" 
                        class="login-logo"
                    >
                @else
                    <div style="font-size: 48px; margin-bottom: 10px;">📱</div>
                @endif
                
                <h1 class="login-title">
                    Samir Group Network Operation Center
                </h1>
                <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 0.85rem; letter-spacing: 0.05em;">
                    SG NOC — Sign in to continue
                </p>
            </div>
            
            <!-- Login Form -->
            <div class="login-body">
                <!-- Session Status -->
                @if (session('status'))
                    <div class="alert alert-success mb-3">
                        {{ session('status') }}
                    </div>
                @endif

                <!-- SSO / General Errors -->
                @if (session('error'))
                    <div class="alert alert-danger mb-3">
                        ⚠ {{ session('error') }}
                    </div>
                @endif

                <!-- Validation Errors -->
                @if ($errors->any())
                    <div class="alert alert-danger mb-3">
                        <strong>Whoops!</strong> There were some problems with your input.
                    </div>
                @endif
                
                <form method="POST" action="{{ route('login') }}">
                    @csrf
                    
                    <!-- Email -->
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input 
                            type="email" 
                            class="form-control @error('email') is-invalid @enderror" 
                            id="email" 
                            name="email" 
                            value="{{ old('email') }}" 
                            required 
                            autofocus
                            placeholder="Enter your email"
                        >
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    
                    <!-- Password -->
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input 
                            type="password" 
                            class="form-control @error('password') is-invalid @enderror" 
                            id="password" 
                            name="password" 
                            required
                            placeholder="Enter your password"
                        >
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    
                    <!-- Remember Me & Forgot Password -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember">
                            <label class="form-check-label" for="remember">
                                Remember me
                            </label>
                        </div>
                        
                        @if (Route::has('password.request'))
                            <a href="{{ route('password.request') }}" class="forgot-link">
                                Forgot password?
                            </a>
                        @endif
                    </div>
                    
                    <!-- Login Button -->
                    <button type="submit" class="btn btn-login">
                        Sign In
                    </button>
                </form>

                {{-- SSO is reserved for the Remote Browser Portal.
                     Admins use password login here; SSO users land in /portal only. --}}
                <div class="mt-4 text-center">
                    <small class="text-muted">
                        Remote Browser user?
                        <a href="{{ route('portal.login') }}" class="fw-semibold">Sign in here</a>
                    </small>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="text-center mt-3">
            <small style="color: rgba(255,255,255,0.8);">
                &copy; {{ date('Y') }} Samir Group. All rights reserved.
            </small>
        </div>
    </div>
</body>
</html>
