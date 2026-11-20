<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        // Webhooks and the fake Pixelforge's images sit outside every middleware group, so no
        // session cookie or CSRF check applies.
        then: function (): void {
            Route::group([], __DIR__ . '/../routes/webhooks.php');
            Route::group([], __DIR__ . '/../routes/fake-pixelforge.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Answer only for APP_URL's host and its subdomains, outside local and tests. Production's
        // proxy chain hands PHP a client's X-Forwarded-Host as the Host, so without this every
        // URL the app builds for that request would point at the spoofed host (D-21).
        $middleware->trustHosts();

        // The prompt is counted, priced and sent to Pixelforge exactly as typed, as the
        // Generate page counts it; Str::trim strips more than JS trim() would (D-37).
        $middleware->trimStrings(except: ['prompt']);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
