<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\Drivers\FakePixelforgeClient;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use App\Support\Pixelforge\ThrottledPixelforgeClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `PIXELFORGE_DRIVER=fake` binds the fake, and never in production.
 */
final class FakePixelforgeDriverTest extends TestCase
{
    public function test_the_fake_driver_binds_a_fresh_fake_client_behind_the_rate_budget(): void
    {
        config(['pixelforge.driver' => FakePixelforgeClient::DRIVER]);

        $client = $this->app->make(PixelforgeClient::class);
        $again = $this->app->make(PixelforgeClient::class);

        $this->assertInstanceOf(ThrottledPixelforgeClient::class, $client);
        $this->assertInstanceOf(ThrottledPixelforgeClient::class, $again);
        $this->assertInstanceOf(FakePixelforgeClient::class, $client->client);
        $this->assertNotSame($client->client, $again->client);
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

    /**
     * The fake can only deliver its webhooks signed, so a secret is what it needs to run.
     */
    public function test_with_a_webhook_secret_the_fake_is_configured(): void
    {
        config(['pixelforge.driver' => FakePixelforgeClient::DRIVER, 'pixelforge.webhook_secret' => 'whsec_test_fake']);

        $this->assertTrue($this->app->make(PixelforgeClient::class)->isConfigured());
    }

    #[DataProvider('missingSecrets')]
    public function test_without_a_webhook_secret_the_fake_is_not_configured(?string $secret): void
    {
        config(['pixelforge.driver' => FakePixelforgeClient::DRIVER, 'pixelforge.webhook_secret' => $secret]);

        $this->assertFalse($this->app->make(PixelforgeClient::class)->isConfigured());
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function missingSecrets(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
        ];
    }
}
