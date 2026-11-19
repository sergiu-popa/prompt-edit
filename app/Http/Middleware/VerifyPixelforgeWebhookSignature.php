<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Pixelforge\DataTransferObjects\WebhookDeliveryData;
use App\Support\Pixelforge\Exceptions\InvalidWebhookSignatureException;
use App\Support\Pixelforge\Exceptions\WebhookSecretNotConfiguredException;
use App\Support\Pixelforge\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets through only a request whose `Pixelforge-Signature` matches its exact
 * raw body. Nothing is persisted before this passes. The logs carry the reason
 * and the unsigned event id header, cut to the length a real one can have,
 * never the body, the signature or the secret.
 */
final readonly class VerifyPixelforgeWebhookSignature
{
    public function __construct(private WebhookSignature $signature) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->signature->verify($request->getContent(), $request->header(WebhookSignature::HEADER));
        } catch (WebhookSecretNotConfiguredException) {
            Log::critical('Refused a Pixelforge webhook: no webhook secret is configured.', [
                'event_id' => $this->loggedEventId($request),
            ]);

            return response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (InvalidWebhookSignatureException $exception) {
            Log::warning('Refused a Pixelforge webhook with an invalid signature.', [
                'reason' => $exception->reason->value,
                'event_id' => $this->loggedEventId($request),
            ]);

            return response('', Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }

    /**
     * No rate limiter applies and the header is not signed, so an
     * unauthenticated sender must not be able to write header-sized values
     * into the log.
     */
    private function loggedEventId(Request $request): ?string
    {
        $eventId = $request->header(WebhookDeliveryData::EVENT_ID_HEADER);

        return $eventId === null ? null : mb_substr($eventId, 0, WebhookDeliveryData::MAX_FIELD_LENGTH);
    }
}
