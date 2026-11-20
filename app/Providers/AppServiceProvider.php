<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Billing\ValueObjects\PricingTable;
use App\Http\Controllers\Generation\CreateGenerationController;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\Drivers\FakePixelforgeClient;
use App\Support\Pixelforge\Drivers\HttpPixelforgeClient;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use App\Support\Pixelforge\ThrottledPixelforgeClient;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
     * while the app boots. Whichever driver is configured, the fake too, takes
     * every call from the one shared budget (D-44).
     *
     * @throws PixelforgeNotConfiguredException
     */
    private function pixelforgeClient(Application $app): PixelforgeClient
    {
        $config = $app->make('config');
        $driver = $config->string('pixelforge.driver');

        $client = match ($driver) {
            HttpPixelforgeClient::DRIVER => $app->make(HttpPixelforgeClient::class),
            FakePixelforgeClient::DRIVER => $this->fakePixelforgeClient($app),
            default => throw PixelforgeNotConfiguredException::unknownDriver($driver),
        };

        return new ThrottledPixelforgeClient(
            $client,
            new CacheRateLimiter($app->make('cache')->store($config->string('pixelforge.rate_limit_store'))),
            $config->integer('pixelforge.rate_limit_per_minute'),
        );
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
        $this->limitGenerationSubmissions();
    }

    /**
     * Per user, a configurable number a minute (D-39). Over the limit, the user goes back
     * to Generate with a form-level error, not a bare 429, which Inertia would show as an
     * error modal.
     */
    private function limitGenerationSubmissions(): void
    {
        RateLimiter::for(CreateGenerationController::RATE_LIMITER, fn (Request $request): Limit => Limit::perMinute(
            $this->app->make('config')->integer('generations.submissions_per_minute'),
        )
            ->by((string) $request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers): RedirectResponse => back()->withErrors([
                'submission' => sprintf("You're submitting too quickly. Try again in %d s.", $headers['Retry-After'] ?? 60),
            ])));
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
