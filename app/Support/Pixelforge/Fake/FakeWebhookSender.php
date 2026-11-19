<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Fake;

use App\Support\Pixelforge\DataTransferObjects\WebhookDeliveryData;
use App\Support\Pixelforge\Enums\WebhookEventType;
use App\Support\Pixelforge\Exceptions\WebhookSecretNotConfiguredException;
use App\Support\Pixelforge\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Posts the fake's webhooks to our real endpoint, as Pixelforge sends them:
 * the docs' envelope and headers, signed by PE-008's signer, so every local
 * run goes through verification, dedupe and the ledger. A failed delivery is
 * logged and never retried: PE-014 recovers a lost terminal webhook. The
 * body, the signature and the secret are never logged.
 */
final readonly class FakeWebhookSender
{
    private const string USER_AGENT = 'Pixelforge-Webhooks/1.0';

    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private WebhookSignature $signature,
        #[Config('pixelforge.fake.webhook_base_url')]
        private string $webhookBaseUrl,
    ) {}

    /**
     * One event about the generation as it is now. A `[duplicate]`
     * generation's event is sent twice: one body and one event id, each copy
     * signed at its own send time.
     *
     * @throws WebhookSecretNotConfiguredException before anything is sent
     */
    public function deliver(FakePixelforgeGeneration $generation, WebhookEventType $type): void
    {
        $eventId = 'evt_' . Str::ulid();

        $body = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type->value,
            'created_at' => CarbonImmutable::now()->toIso8601ZuluString(),
            'data' => ['object' => $generation->snapshot],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $copies = $generation->duplicates_webhooks ? 2 : 1;

        for ($copy = 1; $copy <= $copies; $copy++) {
            $this->send($generation, $type, $eventId, $body);
        }
    }

    /**
     * @throws WebhookSecretNotConfiguredException
     */
    private function send(FakePixelforgeGeneration $generation, WebhookEventType $type, string $eventId, string $body): void
    {
        $signature = $this->signature->sign($body, CarbonImmutable::now()->getTimestamp());

        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->withHeaders([
                    WebhookDeliveryData::EVENT_ID_HEADER => $eventId,
                    WebhookDeliveryData::EVENT_TYPE_HEADER => $type->value,
                    WebhookDeliveryData::DELIVERY_ATTEMPT_HEADER => '1',
                    WebhookSignature::HEADER => $signature,
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->post($this->endpoint());
        } catch (ConnectionException) {
            $this->logUndelivered($generation, $type, $eventId, null);

            return;
        }

        if (! $response->successful()) {
            $this->logUndelivered($generation, $type, $eventId, $response->status());
        }
    }

    private function endpoint(): string
    {
        return rtrim($this->webhookBaseUrl, '/') . route('webhooks.pixelforge', absolute: false);
    }

    private function logUndelivered(FakePixelforgeGeneration $generation, WebhookEventType $type, string $eventId, ?int $status): void
    {
        Log::warning('The fake Pixelforge could not deliver a webhook; it is not retried.', [
            'event_id' => $eventId,
            'type' => $type->value,
            'provider_id' => $generation->id,
            'status' => $status,
        ]);
    }
}
