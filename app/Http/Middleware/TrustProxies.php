<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Trust X-Forwarded-* only from the proxies listed in TRUSTED_PROXIES (comma-separated
 * IPs/CIDRs, or "*" for "whoever connects"). Unset = trust nobody, so the client IP used
 * for the login rate limit cannot be spoofed with a header.
 *
 * Read from config (config/session.php) at request time, so it survives config:cache.
 */
class TrustProxies extends Middleware
{
    protected function proxies()
    {
        $value = trim((string) config('session.trusted_proxies', ''));
        if ($value === '') {
            return null;
        }
        if ($value === '*' || $value === '**') {
            return $value;
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value)), fn ($p) => $p !== ''));
    }
}
