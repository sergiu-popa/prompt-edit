<?php

declare(strict_types=1);

use App\Http\Controllers\ShowReconcilerHealthController;
use Illuminate\Support\Facades\Route;

/*
 * Loaded outside the `web` group, so an uptime check gets no session or cookie.
 * `/up` keeps meaning "the app boots"; this says whether the reconciler runs (D-59).
 */
Route::get('/health/reconciler', ShowReconcilerHealthController::class)
    ->name('health.reconciler');
