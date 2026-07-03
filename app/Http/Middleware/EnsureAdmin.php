<?php

namespace App\Http\Middleware;

use App\Services\SecurityAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            app(SecurityAuditService::class)->record('authorization.admin_denied', $request->user(), request: $request);
        }
        abort_unless($request->user()?->isAdmin(), 403);

        return $next($request);
    }
}
