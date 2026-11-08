<?php

namespace Tests\Feature;

class ExampleTest extends FeatureTestCase
{
    public function test_health_endpoint_is_public(): void
    {
        $this->get('/up')->assertOk();
    }
}
