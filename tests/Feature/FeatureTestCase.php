<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProxApps;
use Tests\TestCase;

abstract class FeatureTestCase extends TestCase
{
    use InteractsWithProxApps;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProxApps();
    }

    protected function tearDown(): void
    {
        $this->tearDownProxApps();
        parent::tearDown();
    }
}
