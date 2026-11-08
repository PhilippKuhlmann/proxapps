<?php

namespace App\Http\Middleware;

use App\Support\Core;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes PK95 Core calls: only in Core mode, only with Core's service token.
 */
class CoreToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Core::enabled()) {
            return response()->json(['error' => 'Diese Instanz läuft nicht unter PK95 Core'], 404);
        }
        if (! Core::authorized($request)) {
            return response()->json(['error' => 'Ungültiger Service-Token'], 401);
        }

        return $next($request);
    }
}
