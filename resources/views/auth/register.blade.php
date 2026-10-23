<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — LeadPilot AI</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; color: #f8fafc; }
        .login-card { background: rgba(255,255,255,0.98); border: 1px solid rgba(255,255,255,0.15); border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.35); width: 100%; max-width: 480px; padding: 2.25rem; color: #0f172a; }
        .brand-icon { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.5rem; box-shadow: 0 8px 16px rgba(79,70,229,0.35); margin: 0 auto 1.25rem; }
        .btn-auth { background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%); border: none; color: #fff; font-weight: 600; padding: 0.75rem; border-radius: 8px; box-shadow: 0 4px 14px rgba(79,70,229,0.3); transition: all 0.2s ease; }
        .btn-auth:hover { background: linear-gradient(135deg, #4338ca 0%, #3730a3 100%); color: #fff; transform: translateY(-1px); }
        .pw-hint { font-size: 0.75rem; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="text-center mb-4">
        <div class="brand-icon"><i class="bi bi-person-plus"></i></div>
        <h4 class="fw-bold mb-1">Create your account</h4>
        <p class="text-muted small mb-0">LeadPilot AI · B2B Sales Intelligence & Outreach Automation</p>
    </div>

    @if($errors->any())
        <div class="alert alert-danger py-2 px-3 small border-0 mb-3">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($firstUser)
        <div class="alert alert-info py-2 px-3 small border-0 mb-3">
            <i class="bi bi-shield-check me-1"></i> This is a fresh installation: the first account becomes the <strong>administrator</strong>.
        </div>
    @endif

    <form action="{{ route('register.post') }}" method="POST" autocomplete="on">
        @csrf
        <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary" for="name">Full name</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-person"></i></span>
                <input type="text" name="name" id="name" class="form-control border-start-0" placeholder="Your name" value="{{ old('name') }}" required autofocus maxlength="255">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary" for="email">Work email</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                <input type="email" name="email" id="email" class="form-control border-start-0" placeholder="name@company.com" value="{{ old('email') }}" required maxlength="255">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary" for="phone">Phone <span class="text-muted fw-normal">(optional)</span></label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-telephone"></i></span>
                <input type="text" name="phone" id="phone" class="form-control border-start-0" placeholder="+92 3xx xxxxxxx" value="{{ old('phone') }}" maxlength="50">
            </div>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-md-6">
                <label class="form-label small fw-semibold text-secondary" for="password">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="password" class="form-control border-start-0" placeholder="••••••••" required minlength="8" autocomplete="new-password">
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold text-secondary" for="password_confirmation">Confirm password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" name="password_confirmation" id="password_confirmation" class="form-control border-start-0" placeholder="••••••••" required minlength="8" autocomplete="new-password">
                </div>
            </div>
        </div>
        <div class="text-muted pw-hint mb-3">At least 8 characters with a letter and a number.</div>

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" name="terms" id="terms" value="1" {{ old('terms') ? 'checked' : '' }} required>
            <label class="form-check-label small text-secondary" for="terms">
                I will use LeadPilot only for lawful B2B outreach and will honour unsubscribe requests.
            </label>
        </div>

        <button type="submit" class="btn btn-auth w-100 mb-3">
            <i class="bi bi-person-check me-1"></i> Create account
        </button>
    </form>

    <div class="pt-3 border-top text-center small text-muted">
        Already have an account? <a href="{{ route('login') }}" class="fw-semibold text-decoration-none">Sign in</a>
    </div>
</div>

</body>
</html>
