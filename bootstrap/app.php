<?php

use App\Http\Middleware\CoreModeBlocksWeb;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustProxies;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // PK95 Core: /core/* and /api/bridge/* with the service token, outside the web group.
        then: fn () => Route::middleware('api')->group(__DIR__.'/../routes/core.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Core mode: the own web interface is off (prepend: before session and login redirects).
        $middleware->web(prepend: [CoreModeBlocksWeb::class]);
        // A fresh installation has no account yet: send everybody to the first-run page.
        $middleware->redirectGuestsTo(fn () => User::query()->exists() ? route('login') : route('setup'));
        $middleware->redirectUsersTo('/');
        $middleware->web(append: [SecurityHeaders::class]);
        // Forwarded headers only from the proxies in TRUSTED_PROXIES (read via config at request time).
        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, TrustProxies::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
