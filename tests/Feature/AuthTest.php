<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\SetupController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

class AuthTest extends FeatureTestCase
{
    private ?string $storageDir = null;

    protected function tearDown(): void
    {
        if ($this->storageDir !== null) {
            File::deleteDirectory($this->storageDir);
        }
        parent::tearDown();
    }

    /** Point storage_path() at a private temp dir, so a setup.token never touches storage/. */
    private function useTempStorage(): void
    {
        $this->storageDir = sys_get_temp_dir().'/proxapps-auth-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->storageDir.'/app/private');
        $this->app->useStoragePath($this->storageDir);
    }

    private function writeSetupToken(string $token): void
    {
        file_put_contents(SetupController::tokenPath(), $token."\n");
    }

    public function test_fresh_install_redirects_everything_to_setup(): void
    {
        $this->get('/')->assertRedirect(route('setup'));
        $this->get('/login')->assertRedirect(route('setup'));
        $this->get('/setup')->assertOk()->assertSee('Ersteinrichtung')->assertSee('Konto anlegen');
    }

    public function test_setup_validates_input(): void
    {
        $this->from('/setup')->post('/setup', ['username' => 'a', 'password' => 'short', 'password2' => 'other'])
            ->assertRedirect('/setup')
            ->assertSessionHasErrors(['username', 'password'])
            ->assertSessionDoesntHaveErrors('password2');

        $this->from('/setup')->post('/setup', ['username' => 'admin', 'password' => 'long-enough-1', 'password2' => 'other-value-1'])
            ->assertSessionHasErrors(['password2' => 'Die Passwörter stimmen nicht überein.']);

        $this->assertSame(0, User::query()->count());
    }

    public function test_setup_creates_first_user_and_signs_in(): void
    {
        $this->post('/setup', ['username' => 'admin', 'password' => 'correct-horse', 'password2' => 'correct-horse'])
            ->assertRedirect(route('connections.create'))
            ->assertSessionHas('success');

        $this->assertAuthenticated();
        $this->assertTrue(Hash::check('correct-horse', User::query()->firstOrFail()->password));

        // Only once: afterwards /setup is closed.
        auth()->logout();
        $this->get('/setup')->assertRedirect(route('login'));
        $this->post('/setup', ['username' => 'evil', 'password' => 'correct-horse', 'password2' => 'correct-horse'])
            ->assertRedirect(route('login'));
        $this->assertSame(1, User::query()->count());
    }

    public function test_setup_without_token_file_needs_no_code(): void
    {
        $this->useTempStorage();
        $this->assertNull(SetupController::currentToken());

        $this->get('/setup')->assertOk()->assertDontSee('Einrichtungscode');
        $this->post('/setup', ['username' => 'admin', 'password' => 'correct-horse', 'password2' => 'correct-horse'])
            ->assertRedirect(route('connections.create'));
        $this->assertSame(1, User::query()->count());
    }

    public function test_setup_requires_the_code_from_the_token_file(): void
    {
        $this->useTempStorage();
        $this->writeSetupToken('ABCDE-FGHJK-MNPQR-STUVW');

        $this->get('/setup')->assertOk()
            ->assertSee('Einrichtungscode')
            ->assertSee('Steht im Container-Log: docker logs proxapps');

        foreach (['', 'WRONG-CODE', 'ABCDE-FGHJK-MNPQR-STUVX'] as $code) {
            $this->from('/setup')->post('/setup', ['code' => $code, 'username' => 'admin',
                'password' => 'correct-horse', 'password2' => 'correct-horse'])
                ->assertRedirect('/setup')
                ->assertSessionHasErrors('code');
        }
        $this->assertSame(0, User::query()->count());
        $this->assertGuest();

        // Case and spaces do not matter.
        $this->post('/setup', ['code' => ' abcde-fghjk-mnpqr-stuvw ', 'username' => 'admin',
            'password' => 'correct-horse', 'password2' => 'correct-horse'])
            ->assertRedirect(route('connections.create'));
        $this->assertSame(1, User::query()->count());
        $this->assertAuthenticated();
        $this->assertFileDoesNotExist(SetupController::tokenPath());
    }

    public function test_empty_token_file_fails_closed(): void
    {
        $this->useTempStorage();
        file_put_contents(SetupController::tokenPath(), '');

        $this->from('/setup')->post('/setup', ['code' => '', 'username' => 'admin',
            'password' => 'correct-horse', 'password2' => 'correct-horse'])
            ->assertSessionHasErrors('code');
        $this->assertSame(0, User::query()->count());
    }

    public function test_setup_create_rechecks_inside_the_transaction(): void
    {
        $this->useTempStorage();
        // Simulates a parallel request that creates its account right after this request's
        // first "does a user exist?" check: the re-check inside the transaction must see it.
        $done = false;
        DB::listen(function ($query) use (&$done) {
            if (! $done && str_contains($query->sql, 'exists') && str_contains($query->sql, '"users"')) {
                $done = true;
                DB::table('users')->insert(['username' => 'other', 'password' => Hash::make('x-long-password'),
                    'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->post('/setup', ['username' => 'admin', 'password' => 'correct-horse', 'password2' => 'correct-horse'])
            ->assertRedirect(route('login'));
        $this->assertSame(1, User::query()->count());
        $this->assertSame('other', User::query()->value('username'));
        $this->assertGuest();
    }

    public function test_setup_token_command_creates_prints_and_cleans_up(): void
    {
        $this->useTempStorage();

        $this->artisan('proxapps:setup-token')->expectsOutputToContain('==> Einrichtungscode: ')->assertSuccessful();
        $path = SetupController::tokenPath();
        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $token = trim(file_get_contents($path));
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}(-[A-Z2-9]{5}){3}$/', $token);

        // Restart before setup: the same code is printed again.
        $this->artisan('proxapps:setup-token')->expectsOutput('==> Einrichtungscode: '.$token)->assertSuccessful();
        $this->assertSame($token, trim(file_get_contents($path)));

        // Once an account exists the file is removed and nothing is printed.
        User::factory()->create();
        $this->artisan('proxapps:setup-token')->doesntExpectOutputToContain('Einrichtungscode')->assertSuccessful();
        $this->assertFileDoesNotExist($path);
    }

    public function test_guests_are_redirected_to_login_once_a_user_exists(): void
    {
        User::factory()->create();

        foreach (['/', '/apps', '/apps/adguard-home', '/connections', '/deployments', '/settings', '/profile'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_login_page_renders_the_dokuvault_sheet(): void
    {
        User::factory()->create();

        $this->get('/login')->assertOk()
            ->assertSee('Anmeldung')
            ->assertSee('netzplan-raster', false)
            ->assertSee('img/logo.svg', false);
    }

    public function test_login_and_logout(): void
    {
        User::factory()->create(['username' => 'admin', 'password' => 'correct-horse']);

        $this->from('/login')->post('/login', ['username' => 'admin', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['username' => 'Benutzername oder Passwort ist falsch.']);
        $this->assertGuest();

        $this->post('/login', ['username' => 'admin', 'password' => 'correct-horse'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $this->get('/login')->assertRedirect('/');

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_per_ip(): void
    {
        User::factory()->create(['username' => 'admin', 'password' => 'correct-horse']);

        for ($i = 0; $i < 8; $i++) {
            $this->from('/login')->post('/login', ['username' => 'admin', 'password' => 'wrong-'.$i]);
        }

        // Even the right password is refused now.
        $this->from('/login')->post('/login', ['username' => 'admin', 'password' => 'correct-horse'])
            ->assertSessionHasErrors(['username' => 'Zu viele Fehlversuche. Bitte in einigen Minuten erneut versuchen.']);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_per_username_across_ips(): void
    {
        User::factory()->create(['username' => 'admin', 'password' => 'correct-horse']);

        for ($i = 0; $i < 8; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($i + 1)])
                ->from('/login')->post('/login', ['username' => $i % 2 ? 'ADMIN' : 'admin', 'password' => 'wrong-'.$i]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->from('/login')->post('/login', ['username' => 'Admin', 'password' => 'correct-horse'])
            ->assertSessionHasErrors(['username' => 'Zu viele Fehlversuche. Bitte in einigen Minuten erneut versuchen.']);
        $this->assertGuest();
    }

    public function test_forwarded_for_is_ignored_without_trusted_proxies(): void
    {
        Route::get('/_test/ip', fn () => request()->ip())->middleware('web');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.7'])
            ->assertSee('203.0.113.5');

        config(['session.trusted_proxies' => '203.0.113.0/24']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.7'])
            ->assertSee('198.51.100.7');

        // A proxy that is not listed is not believed.
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.9'])
            ->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.7'])
            ->assertSee('192.0.2.9');
    }

    public function test_password_change_ends_other_sessions_and_remember_logins(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['password' => 'old-password-1', 'remember_token' => 'old-remember-token']);
        $other = User::factory()->create();
        foreach ([['a', $user->id], ['b', $user->id], ['c', $other->id]] as [$id, $uid]) {
            DB::table('sessions')->insert(['id' => 'sess-'.$id, 'user_id' => $uid, 'ip_address' => '192.0.2.1',
                'user_agent' => 'x', 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        }
        $this->actingAs($user);

        $this->post('/profile', ['current' => 'old-password-1', 'password' => 'new-password-1', 'password2' => 'new-password-1'])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame(0, DB::table('sessions')->whereIn('id', ['sess-a', 'sess-b'])->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'sess-c')->count());
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
        $this->assertSame(60, strlen((string) $user->fresh()->remember_token));
    }

    public function test_profile_changes_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        $this->actingAs($user);

        $this->get('/profile')->assertOk()->assertSee('Passwort ändern');

        $this->from('/profile')->post('/profile', ['current' => 'nope', 'password' => 'short', 'password2' => 'short'])
            ->assertSessionHasErrors(['current' => 'Das aktuelle Passwort ist falsch.', 'password' => 'Mindestens 10 Zeichen.']);

        $this->from('/profile')->post('/profile', ['current' => 'old-password-1', 'password' => 'new-password-1', 'password2' => 'different-1'])
            ->assertSessionHasErrors(['password2']);

        $this->post('/profile', ['current' => 'old-password-1', 'password' => 'new-password-1', 'password2' => 'new-password-1'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success', 'Passwort geändert.');
        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
    }

    public function test_security_headers_are_set(): void
    {
        User::factory()->create();

        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
