<?php

use App\Http\Middleware\AdminIdleTimeout;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureOwner;
use App\Http\Middleware\EnsurePasswordConfirmed;
use App\Http\Middleware\EnsureRegistrationEnabled;
use App\Http\Middleware\PreventBackForwardCaching;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'admin' => EnsureAdmin::class,
            'owner' => EnsureOwner::class,
            'password.confirmed' => EnsurePasswordConfirmed::class,
            'admin.idle' => AdminIdleTimeout::class,
            'registration.enabled' => EnsureRegistrationEnabled::class,
            'no.history' => PreventBackForwardCaching::class,
        ]);
        $middleware->redirectGuestsTo(function (Request $request): string {
            if ($request->is('admin') || $request->is('admin/*') || $request->is('owner') || $request->is('owner/*')) {
                return route('admin.login');
            }

            return route('login');
        });
        $middleware->redirectUsersTo(function (Request $request): string {
            $user = $request->user();

            if ($user?->isOwner()) {
                return route('owner.credentials');
            }

            if ($user?->isAdmin()) {
                return route('admin.employees');
            }

            return route('mailbox');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
