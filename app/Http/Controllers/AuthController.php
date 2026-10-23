<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $remember = $request->boolean('remember');

        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();

            $user = Auth::user();
            if (!$user->is_active) {
                Auth::logout();
                throw ValidationException::withMessages([
                    'email' => 'Your account has been deactivated. Please contact an administrator.',
                ]);
            }

            $user->update(['last_login_at' => now()]);
            AuditLog::record('user_login', $user);

            return redirect()->intended(route('dashboard'))
                ->with('success', "Welcome back, {$user->name}!");
        }

        throw ValidationException::withMessages([
            'email' => 'The provided credentials do not match our records.',
        ]);
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            AuditLog::record('user_logout', $user);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('info', 'You have been safely signed out.');
    }

    public function profile()
    {
        return view('auth.profile', ['user' => Auth::user()]);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'current_password' => ['nullable', 'required_with:new_password', 'current_password'],
            'new_password' => ['nullable', 'min:8', 'confirmed'],
        ]);

        $updatePayload = [
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
        ];

        if (!empty($data['new_password'])) {
            $updatePayload['password'] = Hash::make($data['new_password']);
        }

        $user->update($updatePayload);
        AuditLog::record('user_profile_updated', $user);

        return back()->with('success', 'Profile updated successfully.');
    }

    // ------------------------------------------------------------ self-service registration

    protected function registrationEnabled(): bool
    {
        return (bool) config('leadpilot.registration.enabled', true);
    }

    public function showRegister()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        abort_unless($this->registrationEnabled(), 404);

        return view('auth.register', ['firstUser' => User::count() === 0]);
    }

    public function register(Request $request)
    {
        abort_unless($this->registrationEnabled(), 404);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/\d/'],
            'terms' => ['accepted'],
        ], [
            'password.regex' => 'The password must contain at least one letter and one number.',
            'terms.accepted' => 'Please confirm that you will use LeadPilot for lawful B2B outreach.',
        ]);

        // A fresh installation has no users: the first account must be able to configure everything.
        $role = User::count() === 0 ? 'admin' : (string) config('leadpilot.registration.default_role', 'sales');

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'], // hashed by the model cast
            'role' => $role,
            'is_active' => true,
            'last_login_at' => now(),
        ]);
        AuditLog::record('user_registered', $user);

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', "Welcome, {$user->name}! Your account is ready.");
    }
}
