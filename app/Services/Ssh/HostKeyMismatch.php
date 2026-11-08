<?php

namespace App\Services\Ssh;

/**
 * The SSH server's host key is not one Proxmox generated for the new container.
 */
class HostKeyMismatch extends SshException {}
