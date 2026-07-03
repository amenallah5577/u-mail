<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isActive()) {
            $loginRoute = $request->user()?->isAdmin() ? 'admin.login' : 'login';
            Auth::logout();

            return redirect()->route($loginRoute)->withErrors(['email' => 'This account is not active.']);
        }

        return $next($request);
    }
}
