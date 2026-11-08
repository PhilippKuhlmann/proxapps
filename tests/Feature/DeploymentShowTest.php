<?php

namespace Tests\Feature;

use App\Livewire\Deployments\Show;
use App\Models\Deployment;
use App\Services\Deploy\DeploymentLogger;
use Livewire\Livewire;

class DeploymentShowTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function test_active_deployment_polls_and_streams_new_log_lines(): void
    {
        $d = Deployment::factory()->create(['status' => 'running', 'step' => 'create']);
        DeploymentLogger::log($d, 'erste Zeile', 'info', 'validate');

        $c = Livewire::test(Show::class, ['deployment' => $d])
            ->assertSeeHtml('wire:poll.1500ms')
            ->assertSee('erste Zeile');

        DeploymentLogger::log($d, 'zweite Zeile', 'step', 'create');
        $c->call('refreshLive')
            ->assertDispatched('log-lines', fn ($name, $params) => count($params['lines']) === 1
                && $params['lines'][0]['message'] === 'zweite Zeile');

        $this->assertSame('done', $c->instance()->stepState('template'));
        $this->assertSame('active', $c->instance()->stepState('create'));
        $this->assertSame('pending', $c->instance()->stepState('verify'));
    }

    public function test_final_deployment_does_not_poll(): void
    {
        $d = Deployment::factory()->create(['status' => 'failed', 'step' => 'install', 'error' => 'kaputt']);

        $c = Livewire::test(Show::class, ['deployment' => $d])
            ->assertDontSeeHtml('wire:poll.1500ms')
            ->assertSee('Ausrollen fehlgeschlagen')
            ->assertSee('kaputt');
        $this->assertSame('error', $c->instance()->stepState('install'));
    }

    public function test_cancel_sets_flag(): void
    {
        $d = Deployment::factory()->create(['status' => 'running']);

        Livewire::test(Show::class, ['deployment' => $d])
            ->call('cancel')
            ->assertDispatched('notice', kind: 'success', text: 'Abbruch angefordert.');
        $this->assertTrue($d->fresh()->cancel_requested);
        $this->assertSame('running', $d->fresh()->status);
    }

    public function test_cancel_of_a_queued_deployment_finishes_it_at_once(): void
    {
        $d = Deployment::factory()->create(['status' => 'queued']);

        Livewire::test(Show::class, ['deployment' => $d])
            ->call('cancel')
            ->assertDispatched('notice', kind: 'success', text: 'Deployment abgebrochen.')
            ->assertDontSeeHtml('wire:poll.1500ms')
            ->assertSee('Ausrollen abgebrochen');

        $d->refresh();
        $this->assertSame('cancelled', $d->status);
        $this->assertTrue($d->cancel_requested);
        $this->assertSame('Vom Benutzer abgebrochen.', $d->error);
        $this->assertNotNull($d->finished_at);
    }

    public function test_active_entry_cannot_be_deleted_but_final_can(): void
    {
        $active = Deployment::factory()->create(['status' => 'queued']);
        Livewire::test(Show::class, ['deployment' => $active])->call('deleteEntry')
            ->assertDispatched('notice', kind: 'error');
        $this->assertModelExists($active);

        $done = Deployment::factory()->succeeded()->create(['hostname' => 'weg']);
        Livewire::test(Show::class, ['deployment' => $done])->call('deleteEntry')
            ->assertRedirect(route('deployments.index'));
        $this->assertModelMissing($done);
    }

    public function test_reveal_shows_generated_password(): void
    {
        $d = Deployment::factory()->succeeded()->create();

        Livewire::test(Show::class, ['deployment' => $d])
            ->assertDontSee('Secret123456')
            ->call('toggleReveal')
            ->assertSet('showSecrets', true)
            ->assertSee('Secret123456')
            ->call('toggleReveal')
            ->assertDontSee('Secret123456');
    }

    public function test_revealed_secrets_never_enter_the_component_snapshot(): void
    {
        $d = Deployment::factory()->succeeded()->create([
            'secrets' => ['root_password' => 'RootPw987654', 'params' => ['ADMIN_PASS' => 'Secret123456']],
        ]);

        $c = Livewire::test(Show::class, ['deployment' => $d])->call('toggleReveal')
            ->assertSee('Secret123456')
            ->assertSee('RootPw987654');

        $snapshot = json_encode($c->snapshot);
        $this->assertStringNotContainsString('Secret123456', $snapshot);
        $this->assertStringNotContainsString('RootPw987654', $snapshot);
    }

    public function test_container_actions_shown_after_success(): void
    {
        $d = Deployment::factory()->succeeded()->create();

        Livewire::test(Show::class, ['deployment' => $d])
            ->assertSee('Container entfernen')
            ->assertSeeHtml("action('shutdown')");
        $this->assertTrue(Show::containerActionsAvailable($d));
    }

    public function test_no_container_actions_when_failed_before_create(): void
    {
        $d = Deployment::factory()->create(['status' => 'failed', 'step' => 'validate', 'error' => 'kaputt']);

        Livewire::test(Show::class, ['deployment' => $d])
            ->assertDontSee('Container entfernen')
            ->assertDontSeeHtml("action('shutdown')")
            ->assertDontSee('Container wurde automatisch entfernt.')
            ->call('destroyContainer')
            ->assertDispatched('notice', kind: 'error', text: 'Container kann gerade nicht entfernt werden.')
            ->call('action', 'start')
            ->assertDispatched('notice', kind: 'error', text: 'Aktion nicht möglich.');
    }

    public function test_container_actions_when_failed_after_create(): void
    {
        $d = Deployment::factory()->create(['status' => 'failed', 'step' => 'install', 'error' => 'kaputt',
            'result' => ['container_created' => true]]);

        Livewire::test(Show::class, ['deployment' => $d])
            ->assertSee('Container entfernen')
            ->assertSeeHtml("action('shutdown')");
    }

    public function test_no_container_actions_after_automatic_removal(): void
    {
        foreach (['failed', 'cancelled'] as $status) {
            $d = Deployment::factory()->create(['status' => $status, 'step' => 'install', 'error' => 'kaputt',
                'result' => ['container_created' => true, 'container_removed' => true]]);

            $c = Livewire::test(Show::class, ['deployment' => $d])
                ->assertDontSee('Container entfernen')
                ->assertDontSeeHtml("action('shutdown')")
                ->assertSee('Container wurde automatisch entfernt.')
                ->call('action', 'start')
                ->assertDispatched('notice', kind: 'error', text: 'Aktion nicht möglich.');
            $this->assertSame('error', $c->instance()->stepState('install'));
        }
    }

    public function test_no_container_actions_for_removed_entries(): void
    {
        $d = Deployment::factory()->create(['status' => 'removed', 'step' => 'done',
            'result' => ['container_created' => true]]);

        Livewire::test(Show::class, ['deployment' => $d])
            ->assertDontSee('Container entfernen')
            ->assertDontSeeHtml("action('shutdown')");
    }

    public function test_container_actions_are_refused_while_active(): void
    {
        $d = Deployment::factory()->create(['status' => 'running']);

        Livewire::test(Show::class, ['deployment' => $d])->call('action', 'stop')
            ->assertDispatched('notice', kind: 'error', text: 'Aktion nicht möglich.');
    }
}
