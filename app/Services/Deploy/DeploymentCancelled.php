<?php

namespace App\Services\Deploy;

use RuntimeException;

/**
 * Thrown from inside long waits when the user asked to cancel the deployment.
 */
class DeploymentCancelled extends RuntimeException
{
    public function __construct(string $message = 'Ausrollen wurde abgebrochen.')
    {
        parent::__construct($message);
    }
}
