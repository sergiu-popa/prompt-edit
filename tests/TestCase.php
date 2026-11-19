<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Feature tests assert on Inertia responses, not on built assets, and no
     * test anywhere can reach the network: an unfaked request throws.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
    }
}
