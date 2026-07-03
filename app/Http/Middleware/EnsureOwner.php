<?php

namespace App\Http\Middleware;

use App\Services\SecurityAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isOwner()) {
            app(SecurityAuditService::class)->record('authorization.owner_denied', $request->user(), request: $request);
        }
        abort_unless($request->user()?->isOwner(), 403);

        return $next($request);
    }
}
