<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventBackForwardCaching
{
    public const CACHE_CONTROL = 'max-age=0, must-revalidate, no-cache, no-store, private';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', self::CACHE_CONTROL);
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Vary', 'Cookie', false);

        return $response;
    }
}
