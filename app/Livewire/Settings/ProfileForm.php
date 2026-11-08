<?php

namespace App\Livewire\Settings;

use App\Http\Support\Net;
use App\Models\NetworkProfile;
use App\Services\Ipam;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create / edit a network profile (validation ported from pyref _validate_profile).
 */
class ProfileForm extends Component
{
    #[Locked]
    public ?int $profileId = null;

    public string $name = '';

    public string $bridge = 'vmbr0';

    public string $vlan = '';

    public string $cidr = '';

    public string $gateway = '';

    public string $dns = '';

    public string $searchdomain = '';

    public string $pool_start = '';

    public string $pool_end = '';

    public string $vmid_pattern = '';

    public bool $firewall = false;

    public bool $is_default = false;

    public function mount(?NetworkProfile $profile = null): void
    {
        if ($profile?->exists) {
            $this->profileId = $profile->id;
            foreach (['name', 'bridge', 'cidr', 'gateway', 'dns', 'searchdomain', 'pool_start', 'pool_end', 'vmid_pattern'] as $k) {
                $this->{$k} = (string) ($profile->{$k} ?? '');
            }
            $this->vlan = $profile->vlan ? (string) $profile->vlan : '';
            $this->firewall = (bool) $profile->firewall;
            $this->is_default = (bool) $profile->is_default;
        }
    }

    public function updated(string $property): void
    {
        $this->resetErrorBag($property);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validateProfile(): array
    {
        $errors = [];
        $v = [];
        foreach (['name', 'bridge', 'vlan', 'cidr', 'gateway', 'dns', 'searchdomain', 'pool_start', 'pool_end', 'vmid_pattern'] as $k) {
            $v[$k] = trim((string) $this->{$k});
        }
        $v['firewall'] = $this->firewall;
        $v['is_default'] = $this->is_default;

        if ($v['name'] === '') {
            $errors['name'] = 'Bitte einen Namen angeben.';
        }
        if (! preg_match(Net::BRIDGE_RE, $v['bridge'])) {
            $errors['bridge'] = 'Bitte eine Bridge angeben, z. B. vmbr0.';
        }
        if ($v['vlan'] !== '') {
            $vlan = filter_var($v['vlan'], FILTER_VALIDATE_INT);
            if ($vlan === false || $vlan < 1 || $vlan > 4094) {
                $errors['vlan'] = 'VLAN-ID 1–4094 oder leer.';
            } else {
                $v['vlan'] = $vlan;
            }
        } else {
            $v['vlan'] = null;
        }

        $net = Net::parseCidr($v['cidr']);
        if ($net === null) {
            $errors['cidr'] = 'Netz in CIDR-Schreibweise, z. B. 10.10.250.0/24.';
        } else {
            $v['cidr'] = $net[0].'/'.$net[1];
        }

        foreach (['gateway', 'pool_start', 'pool_end'] as $k) {
            if ($v[$k] === '') {
                $v[$k] = null;

                continue;
            }
            if (! Net::isIpv4($v[$k])) {
                $errors[$k] = 'Keine gültige IPv4-Adresse.';
            } elseif ($net && ! Net::inNetwork($v[$k], $net[0], $net[1])) {
                $errors[$k] = "Liegt nicht im Netz {$net[0]}/{$net[1]}.";
            }
        }
        if (! isset($errors['pool_start']) && ! isset($errors['pool_end']) && $v['pool_start'] && $v['pool_end']
            && ip2long($v['pool_start']) > ip2long($v['pool_end'])) {
            $errors['pool_end'] = 'Das Ende muss nach dem Anfang liegen.';
        }

        $dns = Net::dnsList($v['dns']);
        if ($dns === null) {
            $errors['dns'] = 'DNS-Server als IP-Adressen, durch Leerzeichen getrennt.';
        } else {
            $v['dns'] = $dns ? implode(' ', $dns) : null;
        }
        if ($v['searchdomain'] !== '' && ! preg_match(Net::HOSTNAME_RE, $v['searchdomain'])) {
            $errors['searchdomain'] = 'Keine gültige Domain.';
        }
        $v['searchdomain'] = $v['searchdomain'] !== '' ? $v['searchdomain'] : null;

        if ($v['vmid_pattern'] !== '') {
            $sample = $net ? Net::firstHost($net[0], $net[1]) : '10.0.0.10';
            if (Ipam::renderVmidPattern($v['vmid_pattern'], $sample, $v['vlan'] ?: 1) === null) {
                $errors['vmid_pattern'] = 'Ungültiges Muster. Erlaubt: {vlan}, {o1}…{o4}, {o4:03}, Ziffern.';
            }
        } else {
            $v['vmid_pattern'] = null;
        }

        return [$v, $errors];
    }

    public function save()
    {
        $this->resetErrorBag();
        [$v, $errors] = $this->validateProfile();
        if ($errors) {
            foreach ($errors as $k => $m) {
                $this->addError($k, $m);
            }

            return null;
        }

        if ($v['is_default']) {
            NetworkProfile::query()->update(['is_default' => false]);
        }
        if ($this->profileId) {
            NetworkProfile::query()->findOrFail($this->profileId)->update($v);
        } else {
            if (! NetworkProfile::query()->exists()) {
                $v['is_default'] = true;
            }
            NetworkProfile::query()->create($v);
        }

        session()->flash('success', "Netzwerkprofil „{$v['name']}“ gespeichert.");

        return $this->redirectRoute('settings');
    }

    /** Live preview of the VMID pattern for a sample address of the network. */
    public function patternPreview(): ?string
    {
        if (trim($this->vmid_pattern) === '') {
            return null;
        }
        $net = Net::parseCidr($this->cidr);
        $ip = $net ? Net::firstHost($net[0], $net[1]) : '10.10.250.89';
        if ($net && trim($this->pool_start) !== '' && Net::isIpv4(trim($this->pool_start))) {
            $ip = trim($this->pool_start);
        }
        $vlan = ctype_digit(trim($this->vlan)) ? (int) $this->vlan : null;
        $vmid = Ipam::renderVmidPattern(trim($this->vmid_pattern), $ip, $vlan);

        return $vmid === null
            ? 'Ergibt mit '.$ip.($vlan ? " in VLAN {$vlan}" : '').' keine gültige VMID.'
            : $ip.($vlan ? " in VLAN {$vlan}" : '').' → VMID '.$vmid;
    }

    public function render()
    {
        return view('livewire.settings.profile-form', ['preview' => $this->patternPreview()])
            ->title($this->profileId ? 'Netzwerkprofil bearbeiten' : 'Neues Netzwerkprofil');
    }
}
