<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
 * and the unsigned event id header, never the body, the signature or the secret.
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
            $this->signature->verify($request->getContent(), $request->header('Pixelforge-Signature'));
        } catch (WebhookSecretNotConfiguredException) {
            Log::critical('Refused a Pixelforge webhook: no webhook secret is configured.', [
                'event_id' => $request->header('Pixelforge-Event-Id'),
            ]);

            return response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (InvalidWebhookSignatureException $exception) {
            Log::warning('Refused a Pixelforge webhook with an invalid signature.', [
                'reason' => $exception->reason->value,
                'event_id' => $request->header('Pixelforge-Event-Id'),
            ]);

            return response('', Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }
}
