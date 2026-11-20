<?php

declare(strict_types=1);

use App\Http\Controllers\Credits\PurchaseCreditsController;
use App\Http\Controllers\Credits\ShowBuyCreditsController;
use App\Http\Controllers\Generation\CreateGenerationController;
use App\Http\Controllers\Generation\ShowGenerationFormController;
use App\Http\Controllers\Generation\ShowGenerationHistoryController;
use App\Http\Controllers\Reporting\ShowOperatorReportController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', ShowGenerationFormController::class)->name('dashboard');
    Route::get('/generations', ShowGenerationHistoryController::class)->name('generations.index');
    Route::post('/generations', CreateGenerationController::class)
        ->middleware('throttle:' . CreateGenerationController::RATE_LIMITER)
        ->name('generations.store');
    Route::get('/report', ShowOperatorReportController::class)
        ->middleware('can:' . ShowOperatorReportController::ABILITY)
        ->name('report');
    Route::get('/credits', ShowBuyCreditsController::class)->name('credits.index');
    Route::post('/credits/purchases', PurchaseCreditsController::class)->name('credits.purchases.store');
});
