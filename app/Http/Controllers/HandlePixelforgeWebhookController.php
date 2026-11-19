<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pixelforge's webhook deliveries, already verified by
 * VerifyPixelforgeWebhookSignature. Any 2xx within 10 s counts as delivered.
 */
final class HandlePixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return response('', Response::HTTP_OK);
    }
}
