<?php

namespace Tests\Unit;

use App\Models\Deployment;
use App\Support\PortProbe;
use Tests\TestCase;

class PortProbeTest extends TestCase
{
    public function test_parse_accepts_host_and_port_only(): void
    {
        $this->assertSame(['192.0.2.53', 21116], PortProbe::parse('192.0.2.53:21116'));
        $this->assertSame(['rustdesk.example.de', 21116], PortProbe::parse(' rustdesk.example.de:21116 '));
        $this->assertSame(['2001:db8::5', 21116], PortProbe::parse('[2001:db8::5]:21116'));
        $this->assertNull(PortProbe::parse('192.0.2.53'));
        $this->assertNull(PortProbe::parse('192.0.2.53:0'));
        $this->assertNull(PortProbe::parse('192.0.2.53:70000'));
        $this->assertNull(PortProbe::parse('http://192.0.2.53:80'));
        $this->assertNull(PortProbe::parse('{ip}:21116'));
    }

    public function test_open_and_wait_detect_a_listening_port(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        $this->assertTrue(PortProbe::open('127.0.0.1', $port, 1.0));
        $this->assertTrue(PortProbe::wait('127.0.0.1', $port, 2, 0.1));

        fclose($server);
        $this->assertFalse(PortProbe::open('127.0.0.1', $port, 0.5));
        $this->assertFalse(PortProbe::wait('127.0.0.1', $port, 1, 0.2));
    }

    public function test_versions_are_normalized_to_the_bare_number(): void
    {
        $this->assertSame('17.0.2', PortProbe::normalizeVersion('v17.0.2'));
        $this->assertSame('2026.8.6', PortProbe::normalizeVersion('version/2026.8.6'));
        $this->assertSame('12.4', PortProbe::normalizeVersion('12.4'));
        $this->assertSame('26.09.3', PortProbe::normalizeVersion(' v26.09.3 '));
        $this->assertSame('latest', PortProbe::normalizeVersion('latest'), 'without digits the value is kept');
        $this->assertNull(PortProbe::normalizeVersion(null));
    }

    public function test_update_available_ignores_the_tag_prefix(): void
    {
        $d = new Deployment(['app_version' => '2026.8.6', 'latest_version' => 'version/2026.8.6']);
        $this->assertFalse($d->appUpdateAvailable());
        $d->latest_version = '2026.10.1';
        $this->assertTrue($d->appUpdateAvailable());
        $d->app_version = 'v2026.10.1';
        $this->assertFalse($d->appUpdateAvailable());
    }
}
