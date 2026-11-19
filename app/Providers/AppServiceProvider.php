<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Billing\ValueObjects\PricingTable;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\Drivers\FakePixelforgeClient;
use App\Support\Pixelforge\Drivers\HttpPixelforgeClient;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            PricingTable::class,
            fn (Application $app): PricingTable => PricingTable::fromSnapshot($app->make('config')->array('pricing')),
        );

        $this->app->bind(PixelforgeClient::class, $this->pixelforgeClient(...));
    }

    /**
     * Built on every resolution, so a changed config applies. Resolution is
     * lazy: an unknown driver fails when the client is first needed, never
     * while the app boots.
     *
     * @throws PixelforgeNotConfiguredException
     */
    private function pixelforgeClient(Application $app): PixelforgeClient
    {
        $driver = $app->make('config')->string('pixelforge.driver');

        return match ($driver) {
            HttpPixelforgeClient::DRIVER => $app->make(HttpPixelforgeClient::class),
            FakePixelforgeClient::DRIVER => $this->fakePixelforgeClient($app),
            default => throw PixelforgeNotConfiguredException::unknownDriver($driver),
        };
    }

    /**
     * @throws PixelforgeNotConfiguredException in production
     */
    private function fakePixelforgeClient(Application $app): FakePixelforgeClient
    {
        $this->refuseFakeDriverInProduction();

        return $app->make(FakePixelforgeClient::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @throws PixelforgeNotConfiguredException when the fake is configured in production
     */
    public function boot(): void
    {
        $this->refuseFakeDriverInProduction();
    }

    /**
     * The fake is test infrastructure: in production it would accept every
     * generation and charge real users for images that do not exist.
     *
     * @throws PixelforgeNotConfiguredException
     */
    private function refuseFakeDriverInProduction(): void
    {
        if ($this->app->environment('production') && $this->app->make('config')->get('pixelforge.driver') === FakePixelforgeClient::DRIVER) {
            throw PixelforgeNotConfiguredException::fakeDriverInProduction();
        }
    }
}
