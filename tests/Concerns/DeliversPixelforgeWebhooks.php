<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Webhook deliveries as Pixelforge sends them: the exact raw body, signed
 * with a known test secret at the frozen now. The developer's `.env` secret
 * never reaches a test, and no request leaves the app.
 *
 * @phpstan-require-extends TestCase
 */
trait DeliversPixelforgeWebhooks
{
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;

    protected const string WEBHOOK_SECRET = 'whsec_test_5f0c3a9e2b7d4c1f8a6e0b3d9c2f7a1e';

    /**
     * Shortly after the generation objects' `completed_at`.
     */
    protected const string FROZEN_NOW = '2026-11-19T00:00:10Z';

    /**
     * Called by Laravel's test lifecycle for every test that uses the trait.
     */
    protected function setUpDeliversPixelforgeWebhooks(): void
    {
        config(['pixelforge.webhook_secret' => self::WEBHOOK_SECRET]);
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    /**
     * The docs' event envelope with a fresh `evt_…` id. A null object leaves
     * `data` empty, as a ping may.
     *
     * @param  ?array<string, mixed>  $object
     * @return array<string, mixed>
     */
    protected static function pixelforgeEvent(string $type, ?array $object, ?string $eventId = null): array
    {
        return [
            'id' => $eventId ?? 'evt_' . strtoupper((string) Str::ulid()),
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-19T00:00:00Z',
            'data' => $object === null ? (object) [] : ['object' => $object],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, ?string>  $headers
     */
    protected function deliverPixelforgeEvent(array $event, array $headers = []): TestResponse
    {
        return $this->deliverPixelforgeWebhook(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $headers);
    }

    /**
     * Posts the body byte for byte. It is signed at now unless the
     * `Pixelforge-Signature` header is given; a null header is left out. The
     * event id and type headers default to the body's, the attempt to 1.
     *
     * @param  array<string, ?string>  $headers
     */
    protected function deliverPixelforgeWebhook(string $body, array $headers = []): TestResponse
    {
        $decoded = json_decode($body, associative: true);
        $envelope = is_array($decoded) ? $decoded : [];

        $headers = array_filter([
            'Content-Type' => 'application/json',
            'User-Agent' => 'Pixelforge-Webhooks/1.0',
            'Pixelforge-Event-Id' => is_string($envelope['id'] ?? null) ? $envelope['id'] : null,
            'Pixelforge-Event-Type' => is_string($envelope['type'] ?? null) ? $envelope['type'] : null,
            'Pixelforge-Delivery-Attempt' => '1',
            'Pixelforge-Signature' => $this->pixelforgeSignatureFor($body, CarbonImmutable::now()->getTimestamp()),
            ...$headers,
        ], fn (?string $value): bool => $value !== null);

        return $this->call(
            'POST',
            '/webhooks/pixelforge',
            server: $this->transformHeadersToServerVars($headers),
            content: $body,
        );
    }

    /**
     * The `Pixelforge-Signature` value for the body, computed here and not by
     * the class under test.
     */
    protected function pixelforgeSignatureFor(string $body, int $timestamp, ?string $secret = null): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $body, $secret ?? self::WEBHOOK_SECRET));
    }

    /**
     * Every recorded event as stored, without casts, in write order.
     *
     * @return list<array<string, mixed>>
     */
    protected function storedWebhookEvents(): array
    {
        return array_values(DB::table('pixelforge_webhook_events')
            ->orderBy('id')
            ->get(['event_id', 'type', 'delivery_attempt', 'generation_id', 'received_at'])
            ->map(fn (object $event): array => (array) $event)
            ->all());
    }

    /**
     * The docs' Lighthouse after its create response: queued, with the
     * provider id `gen_<ULID>` stored, as PE-009's submit will leave it.
     */
    protected function givenTheSubmittedLighthouseGeneration(): Generation
    {
        $generation = $this->givenTheLighthouseGeneration();
        $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Queued));

        return $this->reloadedGeneration($generation);
    }
}
