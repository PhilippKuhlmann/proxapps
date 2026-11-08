<?php

namespace App\Livewire\Connections;

use App\Http\Support\Net;
use App\Models\Connection;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Proxmox\ProxmoxException;
use App\Services\Proxmox\Tls;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Create / edit a Proxmox connection. In "pin" mode the user fetches the certificate
 * fingerprint, compares it with the PVE GUI and accepts it explicitly; on save the
 * certificate is inspected again and must still match the accepted fingerprint.
 */
class Form extends Component
{
    #[Locked]
    public ?int $connectionId = null;

    public string $name = '';

    public string $url = '';

    public string $token_id = '';

    public string $token_secret = '';

    public string $tls_mode = 'pin';

    /** Optional resource pool new containers are created in. */
    public string $pool = '';

    /** The fingerprint the user accepted ("AA:BB:…"). */
    public string $fingerprint = '';

    /** A freshly fetched fingerprint waiting for "Übernehmen". */
    public string $fetched = '';

    public ?string $fetchError = null;

    #[Locked]
    public bool $hasSecret = false;

    public function mount(?Connection $connection = null): void
    {
        if ($connection?->exists) {
            $this->connectionId = $connection->id;
            $this->name = $connection->name;
            $this->url = $connection->url;
            $this->token_id = $connection->token_id;
            $this->tls_mode = $connection->tls_mode === 'verify' ? 'verify' : 'pin';
            $this->pool = (string) $connection->pool;
            $this->fingerprint = $connection->fingerprint ? Tls::formatFingerprint($connection->fingerprint) : '';
            $this->hasSecret = (string) $connection->token_secret !== '';
        }
    }

    public function updated(string $property): void
    {
        $this->resetErrorBag($property);
        if ($property === 'url') {
            // A different server means a different certificate.
            $this->fetched = '';
        }
    }

    public function fetchFingerprint(): void
    {
        $this->fetchError = null;
        $this->fetched = '';
        $this->resetErrorBag(['url', 'fingerprint']);
        try {
            $info = Tls::inspect($this->url);
            $this->url = $info['url'];
            $this->fetched = Tls::formatFingerprint($info['fingerprint']);
        } catch (ProxmoxException $e) {
            $this->fetchError = $e->getMessage();
        }
    }

    public function accept(): void
    {
        $this->fingerprint = $this->fetched;
        $this->fetched = '';
        $this->resetErrorBag('fingerprint');
    }

    public function save()
    {
        $this->resetErrorBag();
        $row = $this->connectionId ? Connection::query()->findOrFail($this->connectionId) : null;

        $name = trim($this->name);
        $tokenId = trim($this->token_id);
        $secret = trim($this->token_secret) !== '' ? trim($this->token_secret) : (string) ($row?->token_secret ?? '');
        $mode = $this->tls_mode === 'verify' ? 'verify' : 'pin';
        $fp = Tls::normalizeFingerprint($this->fingerprint);

        if ($name === '') {
            $this->addError('name', 'Bitte einen Namen angeben.');
        }
        $url = null;
        try {
            $url = Tls::normalizeUrl($this->url);
        } catch (ProxmoxException) {
            $this->addError('url', 'Bitte eine gültige Adresse angeben, z. B. https://pve.example.lan:8006');
        }
        if (! preg_match(Net::TOKEN_RE, $tokenId)) {
            $this->addError('token_id', 'Format: benutzer@realm!tokenname, z. B. proxapps@pve!deploy');
        }
        if ($secret === '') {
            $this->addError('token_secret', 'Bitte das Token-Secret angeben.');
        } elseif ($row && $url !== null && $url !== $row->url && trim($this->token_secret) === '') {
            // Never send the stored secret to a different host without the user re-entering it.
            $this->addError('token_secret', 'Die Adresse hat sich geändert – bitte das Token-Secret erneut eingeben.');
        }
        $pool = trim($this->pool);
        if ($pool !== '' && ! preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]{0,39}$~D', $pool)) {
            $this->addError('pool', 'Ungültiger Poolname (Buchstaben, Ziffern, . _ -).');
        }
        if ($mode === 'pin' && strlen($fp) !== 64) {
            $this->addError('fingerprint', 'Bitte den SHA-256-Fingerabdruck abrufen und bestätigen.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return null;
        }

        // Re-inspect: the certificate must still be the one the user accepted.
        $pin = null;
        if ($mode === 'pin') {
            try {
                $info = Tls::inspect($url);
            } catch (ProxmoxException $e) {
                $this->addError('_form', $e->getMessage());

                return null;
            }
            if (Tls::normalizeFingerprint($info['fingerprint']) !== $fp) {
                $this->fingerprint = '';
                $this->fetched = Tls::formatFingerprint($info['fingerprint']);
                $this->addError('fingerprint', 'Der Fingerabdruck hat sich geändert – der Server zeigt jetzt ein anderes Zertifikat. '
                    .'Bitte den neuen Wert mit der Proxmox-Oberfläche vergleichen und erneut übernehmen.');

                return null;
            }
            $pin = $info['pin'];
        }

        // Test before saving.
        try {
            $client = new ProxmoxClient($url, $tokenId, $secret, $mode, $pin, 10);
            $version = $client->version();
            $nodes = array_column($client->nodes(), 'node');
        } catch (Throwable $e) {
            $this->addError('_form', $e->getMessage());

            return null;
        }
        if ($pool !== '') {
            try {
                $client->get('/pools/'.rawurlencode($pool));
            } catch (Throwable) {
                $this->addError('pool', "Den Pool „{$pool}“ gibt es nicht oder der Token darf ihn nicht sehen (Pool.Audit).");

                return null;
            }
        }

        $data = [
            'name' => $name,
            'url' => $url,
            'token_id' => $tokenId,
            'token_secret' => $secret,
            'tls_mode' => $mode,
            'fingerprint' => $mode === 'pin' ? Tls::formatFingerprint($fp) : null,
            'pin' => $pin,
            'pool' => $pool !== '' ? $pool : null,
        ];
        if ($row) {
            $row->update($data);
        } else {
            Connection::query()->create($data);
        }

        session()->flash('success', 'Verbindung gespeichert – Proxmox VE '.($version['version'] ?? '?')
            .', Nodes: '.implode(', ', $nodes).'.');

        return $this->redirectRoute('connections.index');
    }

    public function render()
    {
        return view('livewire.connections.form')
            ->title($this->connectionId ? 'Verbindung bearbeiten' : 'Neue Verbindung');
    }
}
