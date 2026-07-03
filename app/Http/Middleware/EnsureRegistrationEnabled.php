<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('registration.enabled')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(503, 'Account requests are temporarily unavailable.');
        }

        return redirect()
            ->route('login')
            ->with('status', 'Account requests are temporarily unavailable until contact-email delivery is configured.');
    }
}
