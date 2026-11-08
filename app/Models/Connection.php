<?php

namespace App\Models;

use App\Services\Proxmox\ClientFactory;
use App\Services\Proxmox\ProxmoxClient;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Proxmox VE API endpoint (single node or cluster) reached with an API token.
 *
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $token_id
 * @property string $token_secret
 * @property string $tls_mode
 * @property string|null $fingerprint
 * @property string|null $pin
 * @property string|null $pool
 */
#[Fillable(['name', 'url', 'token_id', 'token_secret', 'tls_mode', 'fingerprint', 'pin', 'pool'])]
#[Hidden(['token_secret'])]
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use HasFactory;

    protected $attributes = [
        'tls_mode' => 'pin',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_secret' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function client(int $timeout = 30): ProxmoxClient
    {
        return app(ClientFactory::class)->make($this, $timeout);
    }
}
