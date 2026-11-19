<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Pixelforge\Drivers\FakePixelforgeClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The fake Pixelforge's image for a succeeded generation: one placeholder PNG
 * behind a root-relative temporary signed URL. The signature binds the
 * generation id, so no row is looked up, and an expired URL is refused as a
 * dead signed URL is. The PNG sits outside `public/`, so nothing serves it
 * around this check.
 */
final class ShowFakePixelforgeImageController extends Controller
{
    private const string PLACEHOLDER = 'images/fake-pixelforge-placeholder.png';

    public function __invoke(Request $request): BinaryFileResponse
    {
        abort_unless(config('pixelforge.driver') === FakePixelforgeClient::DRIVER, 404);
        abort_unless($request->hasValidRelativeSignature(), 403);

        return response()->file(resource_path(self::PLACEHOLDER), ['Content-Type' => 'image/png']);
    }
}
