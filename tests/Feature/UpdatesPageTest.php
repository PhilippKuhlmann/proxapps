<?php

namespace Tests\Feature;

use App\Jobs\CheckUpdates;
use App\Jobs\RollbackUpdate;
use App\Jobs\RunUpdate;
use App\Livewire\Deployments\Show;
use App\Livewire\Updates\Index;
use App\Models\Deployment;
use App\Models\UpdateRun;
use App\Services\Catalog\Catalog;
use App\Services\Updates\UpdateChecker;
use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;

class UpdatesPageTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private function managed(array $attrs = []): Deployment
    {
        $d = Deployment::factory()->succeeded()->create($attrs);
        $d->forceFill(['result' => array_merge((array) $d->result, ['container_created' => true])])->save();

        return $d;
    }

    public function test_page_lists_managed_containers_with_versions(): void
    {
        $this->managed(['hostname' => 'adguard-a', 'app_version' => 'v0.107.80', 'latest_version' => 'v0.107.81', 'os_updates' => 3]);
        Deployment::factory()->create(['hostname' => 'still-running', 'status' => 'running']);

        $this->get('/updates')->assertOk()
            ->assertSee('adguard-a')
            ->assertSee('v0.107.81')
            ->assertSee('3 Paket(e)')
            ->assertSee('Alle aktualisieren (1)')
            ->assertDontSee('still-running');
    }

    public function test_update_queues_one_run_and_refuses_a_second(): void
    {
        Queue::fake();
        $d = $this->managed();

        Livewire::test(Index::class)->call('update', $d->id)->call('update', $d->id);

        $this->assertSame(1, UpdateRun::query()->where('deployment_id', $d->id)->count());
        Queue::assertPushed(RunUpdate::class, 1);
    }

    public function test_update_all_only_takes_containers_with_pending_updates(): void
    {
        Queue::fake();
        $this->managed(['os_updates' => 2]);
        $this->managed(['os_updates' => 0, 'app_version' => 'v1', 'latest_version' => 'v1']);

        Livewire::test(Index::class)->call('updateAll');

        Queue::assertPushed(RunUpdate::class, 1);
    }

    public function test_check_dispatches_job(): void
    {
        Queue::fake();
        Livewire::test(Index::class)->call('checkAll');
        Queue::assertPushed(CheckUpdates::class);
    }

    public function test_settings_are_validated_and_saved(): void
    {
        Livewire::test(Index::class)
            ->set('auto_update_time', '25:00')->set('keep_snapshots', '0')
            ->call('saveSettings')
            ->assertHasErrors(['auto_update_time', 'keep_snapshots'])
            ->set('auto_update_time', '04:30')->set('keep_snapshots', '5')->set('auto_update_enabled', true)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertTrue(Settings::get('auto_update_enabled'));
        $this->assertSame('04:30', Settings::get('auto_update_time'));
        $this->assertSame(5, Settings::get('keep_snapshots'));
    }

    public function test_auto_update_window(): void
    {
        Queue::fake();
        $d = $this->managed();
        $checker = Mockery::mock(UpdateChecker::class);
        $checker->shouldReceive('check')->andReturnUsing(fn (Deployment $x) => $x->forceFill(['os_updates' => 2])->save());
        $this->app->instance(UpdateChecker::class, $checker);

        // disabled → nothing
        Artisan::call('proxapps:auto-update');
        Queue::assertNothingPushed();

        // enabled but outside the window → nothing
        Settings::put('auto_update_enabled', true);
        Settings::put('auto_update_time', now()->setTimezone('Europe/Berlin')->addHours(2)->format('H:i'));
        Artisan::call('proxapps:auto-update');
        Queue::assertNothingPushed();

        // in the window → queued once per day
        Settings::put('auto_update_time', now()->setTimezone('Europe/Berlin')->format('H:i'));
        Artisan::call('proxapps:auto-update');
        Artisan::call('proxapps:auto-update');
        Queue::assertPushed(RunUpdate::class, 1);
        $this->assertSame('schedule', UpdateRun::query()->where('deployment_id', $d->id)->value('trigger'));
    }

    public function test_deployment_page_shows_update_card_and_rollback(): void
    {
        Queue::fake();
        $d = $this->managed(['app_version' => 'v0.107.80']);
        $run = UpdateRun::query()->create(['deployment_id' => $d->id, 'status' => 'success',
            'snapshot' => 'proxapps_upd_20261108_030000', 'snapshot_exists' => true, 'finished_at' => now()]);
        Http::fake(['*' => Http::response(['data' => ['status' => 'running']])]);

        $this->get("/deployments/{$d->id}")->assertOk()
            ->assertSee('Updates')->assertSee('v0.107.80')->assertSee('Zurückrollen');

        Livewire::test(Show::class, ['deployment' => $d])->call('rollback', $run->id);
        Queue::assertPushed(RollbackUpdate::class);
    }

    public function test_nextcloud_aio_catalog_entry(): void
    {
        $app = app(Catalog::class)->get('nextcloud-aio');
        $this->assertNotNull($app, implode("\n", app(Catalog::class)->errors()));
        $this->assertSame(['cores' => 4, 'memory' => 6144, 'swap' => 2048, 'disk' => 64], $app->resources);
        $this->assertTrue($app->lxc['nesting']);
        $this->assertSame('select', $app->param('AIO_MODE')['type']);
        $this->assertSame('AIO_PASSPHRASE', $app->access['password_param']);
        $this->assertStringContainsString('PROXAPPS_SECRET:AIO_PASSPHRASE=', $app->installScript);
        $this->assertStringContainsString('AUTOMATIC_UPDATES=1', (string) $app->updates['script']);
        $this->assertSame('tag_name', $app->updates['latest_version']['json']);

        $this->get('/apps')->assertOk()->assertSee('Nextcloud AIO');
    }

    public function test_vaultwarden_catalog_entry(): void
    {
        $app = app(Catalog::class)->get('vaultwarden');
        $this->assertNotNull($app, implode("\n", app(Catalog::class)->errors()));
        $this->assertSame('ADMIN_TOKEN', $app->access['password_param']);
        $this->assertSame('{ACCESS_URL}', $app->access['url']);
        $this->assertSame('{HEALTH_URL}', $app->healthcheck['url']);
        $this->assertTrue($app->param('ADMIN_TOKEN')['generate']);
        $this->assertSame('select', $app->param('TLS_MODE')['type']);
        $this->assertStringContainsString('argon2', $app->installScript);
        $this->assertStringContainsString('PROXAPPS_VAR:ACCESS_URL=', $app->installScript);
        $this->assertStringContainsString('docker pull', (string) $app->updates['script']);
    }

    public function test_catalog_exposes_update_definition_for_adguard(): void
    {
        $app = app(Catalog::class)->get('adguard-home');
        $this->assertTrue($app->hasAppUpdate());
        $this->assertStringContainsString('AdGuardHome.previous', (string) $app->updates['script']);
        $this->assertSame('version', $app->updates['latest_version']['json']);
    }
}
