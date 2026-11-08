<?php

namespace App\Services\Proxmox;

use App\Models\Connection;

/**
 * Builds API clients for stored connections. Resolved from the container so tests can swap it.
 */
class ClientFactory
{
    public function make(Connection $connection, int $timeout = 30): ProxmoxClient
    {
        return new ProxmoxClient(
            url: $connection->url,
            tokenId: $connection->token_id,
            tokenSecret: (string) $connection->token_secret,
            tlsMode: $connection->tls_mode ?: 'pin',
            pin: $connection->pin,
            timeout: $timeout,
        );
    }
}
