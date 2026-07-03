<?php

namespace App\Http\Middleware;

use App\Services\SecurityAuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminIdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return $next($request);
        }

        $lastActivity = (int) $request->session()->get('admin_last_activity', time());
        if (time() - $lastActivity > config('security.admin_idle_minutes') * 60) {
            app(SecurityAuditService::class)->record('admin.session_expired', $request->user(), request: $request);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors(['email' => 'The administrator session expired due to inactivity.']);
        }

        $request->session()->put('admin_last_activity', time());

        return $next($request);
    }
}
