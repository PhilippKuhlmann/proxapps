<?php

namespace Tests\Unit;

use App\Services\Catalog\Catalog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    public function test_every_bundled_app_is_complete(): void
    {
        $catalog = app(Catalog::class);
        $this->assertSame([], $catalog->errors());
        $ids = array_map(fn ($app) => $app->id, array_values($catalog->all()));
        foreach (['adguard-home', 'nextcloud-aio', 'vaultwarden', 'node-red', 'paperless-ngx', 'immich'] as $id) {
            $this->assertContains($id, $ids);
        }

        foreach ($catalog->all() as $app) {
            $id = $app->id;
            $this->assertNotNull($app->iconPath, "{$id}: icon");
            $this->assertNotSame([], $app->healthcheck, "{$id}: healthcheck");
            // PK95 Core puts every app with a web interface behind the proxy.
            $this->assertMatchesRegularExpression('~^(https?://\{ip\}:\{[A-Z_]+\}|https?://\{ip\}:\d+|\{[A-Z_]+\})$~', $app->proxy['url'] ?? '', "{$id}: proxy.url");
            $this->assertMatchesRegularExpression('~^[a-z0-9-]+$~', $app->proxy['subdomain'] ?? '', "{$id}: proxy.subdomain");
            if (isset($app->proxy['domain_param'])) {
                $this->assertNotNull($app->param($app->proxy['domain_param']), "{$id}: proxy.domain_param");
            }
            // The scripts run with "set -euo pipefail" over SSH: they must at least parse.
            foreach (['install' => $app->installScript, 'update' => (string) ($app->updates['script'] ?? '')] as $kind => $script) {
                if ($script === '') {
                    continue;
                }
                $p = proc_open(['bash', '-n'], [0 => ['pipe', 'r'], 2 => ['pipe', 'w']], $pipes);
                fwrite($pipes[0], "set -euo pipefail\n".$script);
                fclose($pipes[0]);
                $err = stream_get_contents($pipes[2]);
                $this->assertSame(0, proc_close($p), "{$id}: {$kind} script: {$err}");
            }
        }
    }

    public function test_loads_the_real_adguard_catalog_entry(): void
    {
        $catalog = app(Catalog::class);
        $this->assertSame([], $catalog->errors());

        $app = $catalog->get('adguard-home');
        $this->assertNotNull($app);
        $this->assertSame('AdGuard Home', $app->name);
        $this->assertSame('Netzwerk', $app->category);
        $this->assertSame('adguard', $app->hostname);
        $this->assertSame('debian-13-standard', $app->template);
        $this->assertSame(['cores' => 1, 'memory' => 512, 'swap' => 512, 'disk' => 4], $app->resources);
        $this->assertSame(['unprivileged' => true, 'nesting' => true, 'onboot' => true], $app->lxc);
        $this->assertSame(['dns', 'adblock'], $app->tags);
        $this->assertSame(['ADMIN_USER', 'ADMIN_PASS', 'WEB_PORT'], array_column($app->parameters, 'key'));
        $this->assertStringContainsString('AdGuard', $app->installScript);
        $this->assertStringEndsWith('icon.svg', (string) $app->iconPath);
        $this->assertSame('http://{ip}:{WEB_PORT}/login.html', $app->healthcheck['url']);
        $this->assertSame('ADMIN_PASS', $app->access['password_param']);

        $pass = $app->param('ADMIN_PASS');
        $this->assertSame('password', $pass['type']);
        $this->assertTrue($pass['generate']);
        $this->assertSame(8, $pass['min_length']);
        $this->assertSame(['ADMIN_PASS'], $app->secretKeys());
        $port = $app->param('WEB_PORT');
        $this->assertSame(80, $port['default']);
        $this->assertSame([1, 65535], [$port['min'], $port['max']]);
        $this->assertNull($app->param('NOPE'));

        $this->assertContains('adguard-home', array_map(fn ($a) => $a->id, $catalog->all()));
        $this->assertNull($catalog->get('does-not-exist'));
    }

    public function test_render_replaces_known_placeholders_only(): void
    {
        $this->assertSame('http://192.0.2.5:3000/ {unknown} 150',
            Catalog::render('http://{ip}:{WEB_PORT}/ {unknown} {vmid}', ['ip' => '192.0.2.5', 'WEB_PORT' => '3000', 'vmid' => 150]));
        $this->assertSame('{ not a placeholder }', Catalog::render('{ not a placeholder }', []));
    }

    public function test_invalid_entries_are_reported_and_skipped(): void
    {
        $dir = sys_get_temp_dir().'/proxapps-catalog-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists("$dir/good");
        File::put("$dir/good/app.yaml", "name: Gut\ncategory: B\nparameters:\n  - {key: TOKEN, label: Token}\n");
        File::put("$dir/good/install.sh", "echo ok\n");
        File::ensureDirectoryExists("$dir/aaa");
        File::put("$dir/aaa/app.yaml", "name: Alpha\ncategory: A\n");
        File::put("$dir/aaa/install.sh", "echo ok\n");
        File::ensureDirectoryExists("$dir/Bad_Id");
        File::put("$dir/Bad_Id/app.yaml", "name: x\n");
        File::ensureDirectoryExists("$dir/noscript");
        File::put("$dir/noscript/app.yaml", "name: x\n");
        File::ensureDirectoryExists("$dir/badparam");
        File::put("$dir/badparam/app.yaml", "parameters:\n  - {key: lower, label: L}\n");
        File::put("$dir/badparam/install.sh", '');
        File::ensureDirectoryExists("$dir/badtype");
        File::put("$dir/badtype/app.yaml", "parameters:\n  - {key: OK, label: L, type: color}\n");
        File::put("$dir/badtype/install.sh", '');
        File::ensureDirectoryExists("$dir/broken");
        File::put("$dir/broken/app.yaml", "name: [unclosed\n");
        File::put("$dir/broken/install.sh", '');

        try {
            $catalog = new Catalog($dir);
            $this->assertSame(['aaa', 'good'], array_map(fn ($a) => $a->id, $catalog->all()));
            $good = $catalog->get('good');
            $this->assertSame('text', $good->param('TOKEN')['type']);
            $this->assertSame('B', $good->category);
            $this->assertNull($good->iconPath);

            $errors = implode("\n", $catalog->errors());
            $this->assertCount(5, $catalog->errors());
            $this->assertStringContainsString("ungültige id 'Bad_Id'", $errors);
            $this->assertStringContainsString("Installationsskript 'install.sh' fehlt", $errors);
            $this->assertStringContainsString("ungültiger Parameter-Schlüssel 'lower'", $errors);
            $this->assertStringContainsString("unbekannter Parametertyp 'color'", $errors);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
