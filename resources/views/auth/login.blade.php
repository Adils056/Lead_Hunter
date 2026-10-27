<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — LeadPilot AI</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: #f8fafc;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.98);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
            width: 100%;
            max-width: 440px;
            padding: 2.25rem;
            color: #0f172a;
        }
        .brand-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.5rem;
            box-shadow: 0 8px 16px rgba(79, 70, 229, 0.35);
            margin: 0 auto 1.25rem;
        }
        .btn-auth {
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            border: none;
            color: #fff;
            font-weight: 600;
            padding: 0.75rem;
            border-radius: 8px;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
            transition: all 0.2s ease;
        }
        .btn-auth:hover {
            background: linear-gradient(135deg, #4338ca 0%, #3730a3 100%);
            color: #fff;
            transform: translateY(-1px);
        }
        .credential-pill {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .credential-pill:hover {
            background: #e2e8f0;
            border-color: #cbd5e1;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="text-center mb-4">
        <div class="brand-icon">
            <i class="bi bi-radar"></i>
        </div>
        <h4 class="fw-bold mb-1">LeadPilot AI</h4>
        <p class="text-muted small">B2B Sales Intelligence & Outreach Automation</p>
    </div>

    @if($errors->any())
        <div class="alert alert-danger py-2 px-3 small border-0 mb-3">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            {{ $errors->first() }}
        </div>
    @endif

    @if(session('info'))
        <div class="alert alert-info py-2 px-3 small border-0 mb-3">
            {{ session('info') }}
        </div>
    @endif

    <form action="{{ route('login.post') }}" method="POST">
        @csrf
        <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary">Work Email</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                <input type="email" name="email" id="email" class="form-control border-start-0" placeholder="name@company.com" value="{{ old('email', app()->environment('local') ? 'admin@leadpilot.test' : '') }}" required autofocus>
            </div>
        </div>

        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label small fw-semibold text-secondary mb-0">Password</label>
            </div>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" id="password" class="form-control border-start-0 border-end-0" placeholder="••••••••" @if(app()->environment('local')) value="password123" @endif required autocomplete="current-password">
                <button type="button" class="btn btn-light border border-start-0 text-muted" data-toggle-password="password" aria-label="Show password" title="Show password"><i class="bi bi-eye"></i></button>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remember" id="remember" checked>
                <label class="form-check-label small text-secondary" for="remember">Remember me</label>
            </div>
        </div>

        <button type="submit" class="btn btn-auth w-100 mb-3">
            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to Platform
        </button>
    </form>

    @if(config('leadpilot.registration.enabled', true))
    <div class="text-center small text-muted mb-3">
        New here? <a href="{{ route('register') }}" class="fw-semibold text-decoration-none">Create an account</a>
    </div>
    @endif

    @if(app()->environment('local'))
    <div class="pt-3 border-top">
        <div class="text-center text-muted small mb-2 fw-semibold">Quick Login Credentials (local only)</div>
        <div class="row g-2">
            <div class="col-6">
                <div class="credential-pill" onclick="fillCreds('admin@leadpilot.test')">
                    <strong class="d-block text-dark">Alex (Admin)</strong>
                    <span class="text-muted" style="font-size: 0.725rem;">admin@leadpilot.test</span>
                </div>
            </div>
            <div class="col-6">
                <div class="credential-pill" onclick="fillCreds('sales@leadpilot.test')">
                    <strong class="d-block text-dark">Michael (Sales)</strong>
                    <span class="text-muted" style="font-size: 0.725rem;">sales@leadpilot.test</span>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

<script>
// Show / hide password: the eye button next to each password field
document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = document.getElementById(btn.dataset.togglePassword);
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.title = btn.getAttribute('aria-label');
    });
});
</script>

@if(app()->environment('local'))
<script>
function fillCreds(email) {
    document.getElementById('email').value = email;
    document.getElementById('password').value = 'password123';
}
</script>
@endif

</body>
</html>
