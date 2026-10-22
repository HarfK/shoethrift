<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Views use @vite; don't require a built manifest to run tests.
        $this->withoutVite();

        // Never call real services (e.g. HitPay) from tests; use Http::fake().
        Http::preventStrayRequests();
    }
}
