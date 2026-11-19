<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\Drivers\FakePixelforgeClient;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use Tests\TestCase;

/**
 * `PIXELFORGE_DRIVER=fake` binds the fake, and never in production.
 */
final class FakePixelforgeDriverTest extends TestCase
{
    public function test_the_fake_driver_binds_a_fresh_fake_client(): void
    {
        config(['pixelforge.driver' => FakePixelforgeClient::DRIVER]);

        $client = $this->app->make(PixelforgeClient::class);

        $this->assertInstanceOf(FakePixelforgeClient::class, $client);
        $this->assertNotSame($client, $this->app->make(PixelforgeClient::class));
    }

    /**
     * The boot guard already refuses this; the binding refuses it too, in
     * case the driver is changed after boot.
     */
    public function test_the_fake_driver_is_refused_in_production(): void
    {
        config(['pixelforge.driver' => FakePixelforgeClient::DRIVER]);
        $this->app['env'] = 'production';

        $this->expectException(PixelforgeNotConfiguredException::class);
        $this->expectExceptionMessage('PIXELFORGE_DRIVER=fake is not allowed in production');

        $this->app->make(PixelforgeClient::class);
    }
}
