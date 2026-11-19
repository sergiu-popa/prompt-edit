<?php

declare(strict_types=1);

use App\Http\Controllers\Generation\CreateGenerationController;
use App\Http\Controllers\Generation\ShowGenerationFormController;
use App\Http\Controllers\Generation\ShowGenerationHistoryController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', ShowGenerationFormController::class)->name('dashboard');
    Route::get('/generations', ShowGenerationHistoryController::class)->name('generations.index');
    Route::post('/generations', CreateGenerationController::class)->name('generations.store');
});
