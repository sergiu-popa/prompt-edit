<?php

declare(strict_types=1);

use App\Http\Controllers\HandlePixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeWebhookSignature;
use Illuminate\Support\Facades\Route;

/*
 * Loaded outside the `web` group: no session, cookies, CSRF, auth or throttle.
 * Pixelforge proves itself with the signature alone.
 */
Route::post('/webhooks/pixelforge', HandlePixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeWebhookSignature::class)
    ->name('webhooks.pixelforge');
