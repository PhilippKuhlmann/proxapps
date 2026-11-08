<?php

namespace Tests\Feature;

use App\Livewire\Connections\Form;
use App\Livewire\Connections\Index;
use App\Models\Connection;
use App\Models\Deployment;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class ConnectionsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function test_delete_is_refused_while_a_deployment_is_active(): void
    {
        $conn = Connection::factory()->create(['name' => 'Homelab']);
        Deployment::factory()->create(['connection_id' => $conn->id, 'status' => 'running']);

        Livewire::test(Index::class)
            ->call('delete', $conn->id)
            ->assertDispatched('notice', kind: 'error', text: 'Die Verbindung wird gerade von einem laufenden Deployment genutzt.');

        $this->assertModelExists($conn);
    }

    public function test_delete_removes_connection_and_keeps_deployments(): void
    {
        $conn = Connection::factory()->create(['name' => 'Homelab']);
        $d = Deployment::factory()->succeeded()->create(['connection_id' => $conn->id]);

        Livewire::test(Index::class)
            ->call('delete', $conn->id)
            ->assertDispatched('notice', kind: 'success', text: 'Verbindung „Homelab“ gelöscht.');

        $this->assertModelMissing($conn);
        $this->assertNull($d->fresh()->connection_id);
    }

    public function test_test_button_reports_version(): void
    {
        $conn = Connection::factory()->create();

        Livewire::test(Index::class)
            ->call('test', $conn->id)
            ->assertDispatched('notice', kind: 'success', text: 'Verbindung OK – Proxmox VE 9.0.3, 2 Node(s), 2 Gäste.');
    }

    public function test_form_validates_input(): void
    {
        Livewire::test(Form::class)
            ->set('name', '')
            ->set('url', '')
            ->set('token_id', 'root')
            ->call('save')
            ->assertHasErrors(['name', 'url', 'token_id', 'token_secret', 'fingerprint'])
            ->assertSee('Format: benutzer@realm!tokenname, z. B. proxapps@pve!deploy')
            ->assertSee('Bitte den SHA-256-Fingerabdruck abrufen und bestätigen.');

        $this->assertSame(0, Connection::query()->count());
    }

    public function test_verify_mode_tests_and_saves(): void
    {
        Livewire::test(Form::class)
            ->set('name', 'Homelab')
            ->set('url', 'pve.test')
            ->set('token_id', 'proxapps@pve!deploy')
            ->set('token_secret', 'aaaa-bbbb')
            ->set('tls_mode', 'verify')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('connections.index'));

        $conn = Connection::query()->firstOrFail();
        $this->assertSame('https://pve.test:8006', $conn->url);
        $this->assertSame('aaaa-bbbb', $conn->token_secret);
        $this->assertSame('Verbindung gespeichert – Proxmox VE 9.0.3, Nodes: pve1, pve2.', session('success'));
    }

    public function test_edit_keeps_secret_when_left_empty(): void
    {
        $conn = Connection::factory()->create(['tls_mode' => 'verify', 'token_secret' => 'keep-me', 'name' => 'Alt']);

        Livewire::test(Form::class, ['connection' => $conn])
            ->assertSet('token_secret', '')
            ->assertDontSee('keep-me')
            ->set('name', 'Neu')
            ->call('save')
            ->assertHasNoErrors();

        $conn->refresh();
        $this->assertSame('Neu', $conn->name);
        $this->assertSame('keep-me', $conn->token_secret);
    }

    public function test_changed_url_requires_the_secret_again(): void
    {
        $conn = Connection::factory()->create(['tls_mode' => 'verify', 'token_secret' => 'keep-me']);

        Livewire::test(Form::class, ['connection' => $conn])
            ->set('url', 'https://other.test:8006')
            ->call('save')
            ->assertHasErrors('token_secret')
            ->assertSee('Die Adresse hat sich geändert – bitte das Token-Secret erneut eingeben.');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.test'));
        $this->assertSame('https://pve.test:8006', $conn->refresh()->url);
        $this->assertSame('keep-me', $conn->token_secret);
    }

    public function test_same_url_in_other_spelling_keeps_secret(): void
    {
        $conn = Connection::factory()->create(['tls_mode' => 'verify', 'token_secret' => 'keep-me']);

        Livewire::test(Form::class, ['connection' => $conn])
            ->set('url', 'pve.test')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('keep-me', $conn->refresh()->token_secret);
    }

    public function test_failed_api_test_does_not_save(): void
    {
        $this->refakeProxmox(['GET /version' => fn () => Http::response(['data' => null], 401)]);

        Livewire::test(Form::class)
            ->set('name', 'Homelab')
            ->set('url', 'https://pve.test:8006')
            ->set('token_id', 'proxapps@pve!deploy')
            ->set('token_secret', 'wrong')
            ->set('tls_mode', 'verify')
            ->call('save')
            ->assertHasErrors('_form')
            ->assertSee('Anmeldung fehlgeschlagen');

        $this->assertSame(0, Connection::query()->count());
    }
}
