<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Billing\ValueObjects\PricingTable;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
