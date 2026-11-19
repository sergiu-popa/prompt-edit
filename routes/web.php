<?php

declare(strict_types=1);

use App\Http\Controllers\Generation\ShowGenerationFormController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', ShowGenerationFormController::class)->name('dashboard');
});
