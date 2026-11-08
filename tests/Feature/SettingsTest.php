<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index;
use App\Livewire\Settings\ProfileForm;
use App\Models\NetworkProfile;
use App\Services\Ssh\DeployerKey;
use App\Support\Settings;
use Livewire\Livewire;

class SettingsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function test_general_settings_validation(): void
    {
        Livewire::test(Index::class)
            ->set('vmid_min', '50')
            ->set('vmid_max', '10')
            ->set('timezone', 'Mars/Olympus')
            ->set('authorized_keys', "ssh-ed25519 AAAAC3Nza user@host\nkein schlüssel")
            ->call('save')
            ->assertHasErrors(['vmid_min', 'vmid_max', 'timezone', 'authorized_keys'])
            ->assertSee('Mindestens 100.')
            ->assertSee('Unbekannte Zeitzone.');
    }

    public function test_general_settings_are_saved(): void
    {
        Livewire::test(Index::class)
            ->set('vmid_min', '200')
            ->set('vmid_max', '299')
            ->set('timezone', 'UTC')
            ->set('default_tags', 'Prod; DNS!')
            ->set('apt_upgrade', false)
            ->set('authorized_keys', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 user@host')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notice', kind: 'success', text: 'Einstellungen gespeichert.');

        $this->assertSame(200, Settings::get('vmid_min'));
        $this->assertSame(299, Settings::get('vmid_max'));
        $this->assertSame('UTC', Settings::get('timezone'));
        $this->assertSame('prod; dns', Settings::get('default_tags'));
        $this->assertFalse(Settings::get('apt_upgrade'));
    }

    public function test_profile_form_validates_like_pyref(): void
    {
        Livewire::test(ProfileForm::class)
            ->set('name', '')
            ->set('bridge', 'vmbr 0')
            ->set('vlan', '5000')
            ->set('cidr', '10.10.250.0/24')
            ->set('gateway', '10.10.251.1')
            ->set('pool_start', '10.10.250.90')
            ->set('pool_end', '10.10.250.10')
            ->set('dns', '1.1.1.1 kein')
            ->set('vmid_pattern', '{foo}')
            ->call('save')
            ->assertHasErrors(['name', 'bridge', 'vlan', 'gateway', 'pool_end', 'dns', 'vmid_pattern'])
            ->assertSee('Liegt nicht im Netz 10.10.250.0/24.')
            ->assertSee('Das Ende muss nach dem Anfang liegen.');
    }

    public function test_first_profile_becomes_default_and_cidr_is_normalised(): void
    {
        Livewire::test(ProfileForm::class)
            ->set('name', 'Server')
            ->set('cidr', '10.10.250.7/24')
            ->set('vlan', '250')
            ->set('vmid_pattern', '{vlan}{o4:03}')
            ->assertSee('VMID 250001')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings'));

        $p = NetworkProfile::query()->firstOrFail();
        $this->assertSame('10.10.250.0/24', $p->cidr);
        $this->assertTrue($p->is_default);
        $this->assertSame(250, $p->vlan);
    }

    public function test_profile_can_be_deleted(): void
    {
        $p = NetworkProfile::factory()->create();

        Livewire::test(Index::class)->call('deleteProfile', $p->id)
            ->assertDispatched('notice', kind: 'success', text: 'Netzwerkprofil gelöscht.');
        $this->assertModelMissing($p);
    }

    public function test_deployer_key_can_be_regenerated(): void
    {
        $c = Livewire::test(Index::class);
        $before = app(DeployerKey::class)->publicKey();

        $c->call('regenerateKey')->assertDispatched('notice', kind: 'success');
        $this->assertNotSame($before, app(DeployerKey::class)->publicKey());
    }
}
