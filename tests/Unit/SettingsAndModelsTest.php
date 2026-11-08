<?php

namespace Tests\Unit;

use App\Models\Connection;
use App\Models\Deployment;
use App\Models\NetworkProfile;
use App\Models\User;
use App\Services\Ssh\DeployerKey;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SettingsAndModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_defaults_and_put(): void
    {
        $this->assertSame(100, Settings::get('vmid_min'));
        $this->assertSame(999999, Settings::get('vmid_max'));
        $this->assertSame('proxapps', Settings::get('default_tags'));
        $this->assertSame('Europe/Berlin', Settings::get('timezone'));
        $this->assertTrue(Settings::get('apt_upgrade'));
        $this->assertFalse(Settings::get('destroy_on_failure'));
        $this->assertSame('', Settings::get('authorized_keys'));
        $this->assertNull(Settings::get('unknown'));

        Settings::put('vmid_min', 200);
        Settings::put('vmid_min', 300);
        Settings::put('apt_upgrade', false);
        Settings::put('authorized_keys', "ssh-ed25519 AAAA ä\n");

        $this->assertSame(300, Settings::get('vmid_min'));
        $this->assertFalse(Settings::get('apt_upgrade'));
        $all = Settings::all();
        $this->assertSame(300, $all['vmid_min']);
        $this->assertSame("ssh-ed25519 AAAA ä\n", $all['authorized_keys']);
        $this->assertSame('Europe/Berlin', $all['timezone']);
        $this->assertSame(1, DB::table('settings')->where('key', 'vmid_min')->count());
    }

    public function test_encrypted_casts_roundtrip(): void
    {
        $conn = Connection::factory()->create(['token_secret' => 'super-secret-token']);
        $raw = DB::table('connections')->where('id', $conn->id)->value('token_secret');
        $this->assertStringNotContainsString('super-secret-token', $raw);
        $this->assertSame('super-secret-token', Connection::find($conn->id)->token_secret);
        $this->assertArrayNotHasKey('token_secret', $conn->toArray());

        $dep = Deployment::factory()->create([
            'connection_id' => $conn->id,
            'secrets' => ['root_password' => 'RootPw99', 'params' => ['ADMIN_PASS' => 'AdminPw99']],
        ]);
        $raw = DB::table('deployments')->where('id', $dep->id)->value('secrets');
        $this->assertStringNotContainsString('AdminPw99', $raw);
        $fresh = Deployment::find($dep->id);
        $this->assertSame(['root_password' => 'RootPw99', 'params' => ['ADMIN_PASS' => 'AdminPw99']], $fresh->secrets);
        $this->assertSame('static', $fresh->spec['net']['mode']);
        $this->assertArrayNotHasKey('secrets', $fresh->toArray());
        $this->assertTrue($fresh->connection->is($conn));
    }

    public function test_deployment_helpers_and_relations(): void
    {
        $queued = Deployment::factory()->create(['status' => 'queued']);
        $running = Deployment::factory()->create(['status' => 'running']);
        $done = Deployment::factory()->succeeded()->create();

        $this->assertEqualsCanonicalizing([$queued->id, $running->id], Deployment::active()->pluck('id')->all());
        $this->assertTrue($queued->isActive());
        $this->assertFalse($queued->isFinal());
        $this->assertTrue($done->isFinal());

        $done->logs()->create(['message' => 'b', 'level' => 'info']);
        $done->logs()->create(['message' => 'a', 'level' => 'info']);
        $this->assertSame(['b', 'a'], $done->logs->pluck('message')->all());
        $this->assertNull($done->logs->first()->updated_at ?? null);

        $done->connection->delete();
        $this->assertNull($done->fresh()->connection_id);
        $id = $done->id;
        $done->delete();
        $this->assertSame(0, DB::table('deployment_logs')->where('deployment_id', $id)->count());
    }

    public function test_network_profile_prefix_and_user_username(): void
    {
        $this->assertSame(24, (new NetworkProfile(['cidr' => '192.0.2.0/24']))->prefix());
        $this->assertSame(32, (new NetworkProfile(['cidr' => '192.0.2.7']))->prefix());

        $user = User::factory()->create(['username' => 'admin']);
        $this->assertSame('admin', $user->fresh()->username);
        $this->assertNotSame('password', $user->password);
    }

    public function test_deployer_key_is_created_once_with_strict_permissions(): void
    {
        $dir = sys_get_temp_dir().'/proxapps-key-'.bin2hex(random_bytes(4));
        try {
            $key = new DeployerKey("$dir/ssh/id_ed25519");
            $pub = $key->publicKey();
            $this->assertMatchesRegularExpression('~^ssh-ed25519 [A-Za-z0-9+/=]+ proxapps-deployer$~', $pub);
            $this->assertSame(0600, fileperms("$dir/ssh/id_ed25519") & 0777);
            $this->assertSame(0700, fileperms("$dir/ssh") & 0777);

            $this->assertSame($pub, (new DeployerKey("$dir/ssh/id_ed25519"))->publicKey());

            $key->regenerate();
            $this->assertNotSame($pub, $key->publicKey());
            $this->assertSame($key->publicKey(), (new DeployerKey("$dir/ssh/id_ed25519"))->publicKey());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_deployer_key_is_reloaded_when_another_process_regenerates_it(): void
    {
        $dir = sys_get_temp_dir().'/proxapps-key-'.bin2hex(random_bytes(4));
        try {
            $worker = new DeployerKey("$dir/id_ed25519");   // long-running queue worker
            $old = $worker->publicKey();

            $web = new DeployerKey("$dir/id_ed25519");      // "Neues Schlüsselpaar erzeugen" in the UI
            $web->regenerate();

            $this->assertNotSame($old, $web->publicKey());
            $this->assertSame($web->publicKey(), $worker->publicKey());
            $this->assertSame($worker->privateKey(), $worker->privateKey()); // unchanged file → cached
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
