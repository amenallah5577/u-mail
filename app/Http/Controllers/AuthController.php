<?php

namespace App\Http\Controllers;

use App\Http\Middleware\PreventBackForwardCaching;
use App\Models\User;
use App\Services\AuthenticationSecurityService;
use App\Services\SecurityAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.employee-login');
    }

    public function showAdminLogin()
    {
        return view('auth.admin-login');
    }

    public function loginEmployee(Request $request, AuthenticationSecurityService $security, SecurityAuditService $audit)
    {
        return $this->attemptLogin($request, 'employee', route('mailbox'), $security, $audit);
    }

    public function loginAdmin(Request $request, AuthenticationSecurityService $security, SecurityAuditService $audit)
    {
        return $this->attemptLogin($request, 'admin', null, $security, $audit);
    }

    public function sessionStatus(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'authenticated' => (bool) $user?->isActive(),
            'user_id' => $user?->isActive() ? $user->id : null,
        ])->withHeaders([
            'Cache-Control' => PreventBackForwardCaching::CACHE_CONTROL,
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Vary' => 'Cookie',
        ]);
    }

    private function attemptLogin(Request $request, string $role, ?string $destination, AuthenticationSecurityService $security, SecurityAuditService $audit)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if ($request->user()) {
            $audit->record('login.replaced_authenticated_session', $request->user(), request: $request);
            $this->endSession($request);
        }

        $email = strtolower($credentials['email']);
        $user = User::where(fn ($query) => $query->where('email', $email)->orWhere('public_email', $email))
            ->where('role', $role)
            ->first();
        $security->ensureLoginAllowed($request, $role, $email);

        if (! $user || ! $user->isActive() || blank($user->password) || ! Hash::check($credentials['password'], $user->password)) {
            $security->recordLoginFailure($request, $role, $email);
            $audit->record('login.failed', target: $user, metadata: ['role' => $role, 'email' => $email], request: $request);
            throw ValidationException::withMessages([
                'email' => 'The credentials are invalid or the account is inactive.',
            ]);
        }

        $security->clearLoginFailures($request, $role, $email);
        $destination ??= $this->destinationForAdmin($user);
        $remember = $role === 'employee' && $request->boolean('remember');
        if ($user->hasMfa()) {
            $request->session()->regenerate();
            $request->session()->put([
                'mfa.pending_user_id' => $user->id,
                'mfa.destination' => $destination,
                'mfa.remember' => $remember,
            ]);
            $audit->record('login.password_succeeded_mfa_pending', $user, $user, ['role' => $role], $request);

            return redirect()->route('mfa.challenge');
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();
        if ($user->isAdmin()) {
            $request->session()->put('admin_last_activity', time());
        }
        $user->update(['last_login_at' => now()]);
        $audit->record('login.succeeded', $user, $user, ['role' => $role], $request);
        Cookie::queue(Cookie::forget('u_mail_signed_out'));

        return redirect($destination);
    }

    public function logout(Request $request, SecurityAuditService $audit)
    {
        $loginRoute = $request->user()?->isAdmin() ? 'admin.login' : 'login';
        $audit->record('logout', $request->user(), request: $request);
        $this->endSession($request);

        if ($request->expectsJson()) {
            return response()->noContent()->withHeaders($this->logoutHeaders(clearSiteData: false));
        }

        return redirect()->route($loginRoute)->withHeaders($this->logoutHeaders());
    }

    private function logoutHeaders(bool $clearSiteData = true): array
    {
        $headers = [
            'Cache-Control' => PreventBackForwardCaching::CACHE_CONTROL,
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Vary' => 'Cookie',
        ];

        if ($clearSiteData) {
            $headers['Clear-Site-Data'] = '"cache", "storage"';
        }

        return $headers;
    }

    private function destinationForAdmin(User $user): string
    {
        if ($user->isOwner()) {
            return route('owner.credentials');
        }

        return route('admin.employees');
    }

    private function endSession(Request $request): void
    {
        $recaller = Auth::guard()->getRecallerName();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget($recaller));
        Cookie::queue(Cookie::make('u_mail_signed_out', '1', 5, '/', null, $request->isSecure(), false, false, 'Strict'));
    }
}
