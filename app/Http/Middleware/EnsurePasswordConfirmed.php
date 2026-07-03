<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        if (time() - $confirmedAt > config('security.password_confirmation_seconds')) {
            $request->session()->put('url.intended', url()->previous());

            return redirect()->route('password.confirm')->with('status', 'Confirm your password before continuing.');
        }

        return $next($request);
    }
}
