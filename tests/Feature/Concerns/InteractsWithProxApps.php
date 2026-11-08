<?php

namespace Tests\Feature\Concerns;

use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\Unit\Concerns\FakesProxmox;

/**
 * Shared setup for the UI feature tests: no real SSH key in storage/, no real ping,
 * no sleeping, and every Proxmox call answered by the fake (host pve.test only).
 */
trait InteractsWithProxApps
{
    use FakesProxmox;

    protected string $keyDir;

    protected function setUpProxApps(): void
    {
        $this->keyDir = sys_get_temp_dir().'/proxapps-ui-test-'.bin2hex(random_bytes(4));
        config(['proxapps.ssh_key_path' => $this->keyDir.'/id_ed25519']);
        // ping: nobody answers
        Process::fake(['*' => Process::result(exitCode: 1)]);
        Sleep::fake();
        $this->fakeProxmox();
    }

    protected function tearDownProxApps(): void
    {
        if (isset($this->keyDir) && is_dir($this->keyDir)) {
            File::deleteDirectory($this->keyDir);
        }
    }

    /**
     * Replace the Proxmox fake with one using these overrides (Http::fake stubs accumulate,
     * and the first registered one wins - so start from a fresh client factory).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function refakeProxmox(array $overrides): void
    {
        Http::swap(new HttpFactory(app('events')));
        $this->fakeProxmox($overrides);
    }

    protected function signIn(): User
    {
        $user = User::factory()->create(['username' => 'admin']);
        $this->actingAs($user);

        return $user;
    }
}
