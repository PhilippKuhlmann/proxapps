<?php

namespace App\Services\Deploy;

use RuntimeException;

/**
 * An expected deployment failure whose message is shown to the user as is.
 */
class DeployException extends RuntimeException {}
