<?php

namespace App\Livewire\Settings;

use App\Http\Support\Format;
use App\Http\Support\Net;
use App\Models\NetworkProfile;
use App\Services\Ipam;
use App\Services\Ssh\DeployerKey;
use App\Support\Settings;
use DateTimeZone;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Einstellungen')]
class Index extends Component
{
    public string $vmid_min = '';

    public string $vmid_max = '';

    public string $vmid_pattern = '';

    public string $timezone = '';

    public string $default_tags = '';

    public bool $apt_upgrade = true;

    public bool $destroy_on_failure = false;

    public string $authorized_keys = '';

    public function mount(): void
    {
        $s = Settings::all();
        $this->vmid_min = (string) ($s['vmid_min'] ?? 100);
        $this->vmid_max = (string) ($s['vmid_max'] ?? 999999);
        $this->vmid_pattern = (string) ($s['vmid_pattern'] ?? '');
        $this->timezone = (string) ($s['timezone'] ?? 'Europe/Berlin');
        $this->default_tags = (string) ($s['default_tags'] ?? '');
        $this->apt_upgrade = (bool) ($s['apt_upgrade'] ?? true);
        $this->destroy_on_failure = (bool) ($s['destroy_on_failure'] ?? false);
        $this->authorized_keys = (string) ($s['authorized_keys'] ?? '');
    }

    public function updated(string $property): void
    {
        $this->resetErrorBag($property);
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $min = filter_var(trim($this->vmid_min), FILTER_VALIDATE_INT);
        $max = filter_var(trim($this->vmid_max), FILTER_VALIDATE_INT);
        if ($min === false || $min < 100) {
            $this->addError('vmid_min', 'Mindestens 100.');
        }
        if ($max === false || ($min !== false && $min && $max < $min) || $max > 999999999) {
            $this->addError('vmid_max', 'Muss ≥ Minimum und ≤ 999999999 sein.');
        }
        $pattern = trim($this->vmid_pattern);
        if ($pattern !== '' && Ipam::renderVmidPattern($pattern, '10.10.250.55', 250) === null) {
            $this->addError('vmid_pattern', 'Ungültiges Muster. Erlaubt: {vlan}, {o1}…{o4}, {o4:03}, Ziffern.');
        }
        $tz = trim($this->timezone) !== '' ? trim($this->timezone) : 'Europe/Berlin';
        try {
            new DateTimeZone($tz);
        } catch (Throwable) {
            $this->addError('timezone', 'Unbekannte Zeitzone.');
        }
        if (! Net::validSshKeys($this->authorized_keys)) {
            $this->addError('authorized_keys', 'Mindestens eine Zeile ist kein gültiger öffentlicher SSH-Schlüssel.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $tags = (string) preg_replace('/[^a-z0-9_;,\s+.-]/', '', mb_strtolower($this->default_tags));
        Settings::put('vmid_min', $min);
        Settings::put('vmid_max', $max);
        Settings::put('vmid_pattern', $pattern);
        Settings::put('timezone', $tz);
        Settings::put('default_tags', $tags);
        Settings::put('apt_upgrade', $this->apt_upgrade);
        Settings::put('destroy_on_failure', $this->destroy_on_failure);
        Settings::put('authorized_keys', $this->authorized_keys);
        Format::flush();

        $this->timezone = $tz;
        $this->default_tags = $tags;
        $this->dispatch('notice', kind: 'success', text: 'Einstellungen gespeichert.');
    }

    public function deleteProfile(int $id): void
    {
        NetworkProfile::query()->whereKey($id)->delete();
        $this->dispatch('notice', kind: 'success', text: 'Netzwerkprofil gelöscht.');
    }

    public function regenerateKey(DeployerKey $key): void
    {
        try {
            $key->regenerate();
            $this->dispatch('notice', kind: 'success', text: 'Neues Deployer-Schlüsselpaar erzeugt. Bestehende Container behalten den alten Schlüssel.');
        } catch (Throwable $e) {
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());
        }
    }

    public function render(DeployerKey $key)
    {
        try {
            $pubkey = $key->publicKey();
        } catch (Throwable $e) {
            $pubkey = '';
        }

        return view('livewire.settings.index', [
            'profiles' => NetworkProfile::ordered(),
            'pubkey' => $pubkey,
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }
}
