<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $scriptSources = "'self'";
        $frameSources = "'none'";
        if (config('security.turnstile.enabled')) {
            $scriptSources .= ' https://challenges.cloudflare.com';
            $frameSources = 'https://challenges.cloudflare.com';
        }

        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src {$scriptSources}; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; frame-src {$frameSources}; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
