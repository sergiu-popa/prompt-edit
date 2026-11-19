<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Billing\ValueObjects\PricingTable;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
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
            default => throw PixelforgeNotConfiguredException::unknownDriver($driver),
        };
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
