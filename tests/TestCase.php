<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Feature tests assert on Inertia responses, not on built assets.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
