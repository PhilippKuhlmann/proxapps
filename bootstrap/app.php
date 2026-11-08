<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustProxies;
use App\Models\User;
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
