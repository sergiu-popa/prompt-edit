<?php

declare(strict_types=1);

use App\Http\Controllers\ShowFakePixelforgeImageController;
use Illuminate\Support\Facades\Route;

/*
 * The fake Pixelforge's placeholder image (PE-010). Loaded outside the `web`
 * group, so it sets no session or cookie. The controller answers 404 unless
 * the fake is the driver.
 */
Route::get('/fake-pixelforge/images/{generation}.png', ShowFakePixelforgeImageController::class)
    ->where('generation', 'gen_[0-9A-Z]{26}')
    ->name('fake-pixelforge.image');
