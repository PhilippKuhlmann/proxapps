<?php

namespace App\Services\Proxmox;

use RuntimeException;
use Throwable;

class ProxmoxException extends RuntimeException
{
    public ?int $status;

    /** @var array<string, mixed> */
    public array $errors;

    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(string $message, ?int $status = null, array $errors = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->errors = $errors;
    }
}
