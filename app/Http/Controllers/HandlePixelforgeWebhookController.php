<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Generation\Actions\HandlePixelforgeWebhookAction;
use App\Support\Pixelforge\DataTransferObjects\WebhookDeliveryData;
use App\Support\Pixelforge\Exceptions\InvalidWebhookDeliveryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Pixelforge's webhook deliveries, already verified by
 * VerifyPixelforgeWebhookSignature. Any 2xx within 10 s counts as delivered.
 *
 * The body is read from the exact raw bytes, never from the parsed input,
 * which global middleware rewrites. There is no Form Request: a failed
 * validation would redirect, and Pixelforge never follows a redirect. An
 * unexpected exception reaches the framework handler, which reports it and
 * answers 500, so Pixelforge retries.
 */
final class HandlePixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, HandlePixelforgeWebhookAction $handlePixelforgeWebhook): Response
    {
        try {
            $delivery = WebhookDeliveryData::from(
                $request->getContent(),
                $request->header(WebhookDeliveryData::EVENT_ID_HEADER),
                $request->header(WebhookDeliveryData::EVENT_TYPE_HEADER),
                $request->header(WebhookDeliveryData::DELIVERY_ATTEMPT_HEADER),
            );
        } catch (InvalidWebhookDeliveryException $exception) {
            Log::error('Refused a signed Pixelforge webhook whose body cannot be read.', [
                'error' => $exception->getMessage(),
                ...$exception->context(),
            ]);

            return response('', Response::HTTP_BAD_REQUEST);
        }

        $handlePixelforgeWebhook->execute($delivery);

        return response('', Response::HTTP_OK);
    }
}
