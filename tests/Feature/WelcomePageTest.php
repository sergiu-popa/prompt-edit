<?php

declare(strict_types=1);

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class WelcomePageTest extends TestCase
{
    public function test_home_page_is_rendered_by_the_welcome_inertia_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
    }
}
