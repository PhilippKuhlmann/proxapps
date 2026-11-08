<?php

namespace App\Http\Middleware;

use App\Support\Core;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In Core mode the own web interface is off: PK95 Core shows ProxApps in its UI.
 */
class CoreModeBlocksWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Core::enabled()) {
            return response('ProxApps wird über PK95 Core verwaltet.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $next($request);
    }
}
