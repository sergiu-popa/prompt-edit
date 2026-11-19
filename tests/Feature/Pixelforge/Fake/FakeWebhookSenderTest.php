<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Support\Pixelforge\Enums\WebhookEventType;
use App\Support\Pixelforge\Exceptions\WebhookSecretNotConfiguredException;
use App\Support\Pixelforge\Fake\FakePixelforgeGeneration;
use App\Support\Pixelforge\Fake\FakeWebhookSender;
use App\Support\Pixelforge\WebhookSignature;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\TestCase;

/**
 * The fake's webhooks reach our real endpoint as Pixelforge sends them, and
 * a failed delivery is logged, never thrown or retried.
 */
final class FakeWebhookSenderTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithFakePixelforge;
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-11-19T12:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    public function test_a_webhook_is_posted_in_network_with_the_docs_headers(): void
    {
        $generation = $this->givenAFakeGeneration();

        $this->sender()->deliver($generation, WebhookEventType::GenerationProcessing);

        $requests = $this->fakeWebhookRequests();
        $this->assertCount(1, $requests);
        $request = $requests[0];
        $body = self::decoded($request);

        $this->assertSame(['application/json'], $request->header('Content-Type'));
        $this->assertSame(['Pixelforge-Webhooks/1.0'], $request->header('User-Agent'));
        $this->assertSame([$body['id']], $request->header('Pixelforge-Event-Id'));
        $this->assertSame(['generation.processing'], $request->header('Pixelforge-Event-Type'));
        $this->assertSame(['1'], $request->header('Pixelforge-Delivery-Attempt'));
        $this->assertTrue($request->hasHeader(WebhookSignature::HEADER));
        $this->assertFalse($request->hasHeader('Authorization'));
    }

    public function test_the_body_is_the_docs_envelope_around_the_snapshot(): void
    {
        $generation = $this->givenAFakeGeneration();

        $this->sender()->deliver($generation, WebhookEventType::GenerationProcessing);

        $body = self::decoded($this->fakeWebhookRequests()[0]);

        $this->assertSame(['id', 'object', 'type', 'created_at', 'data'], array_keys($body));
        $this->assertIsString($body['id']);
        $this->assertMatchesRegularExpression('/^evt_[0-9A-Z]{26}\z/', $body['id']);
        $this->assertSame('event', $body['object']);
        $this->assertSame('generation.processing', $body['type']);
        $this->assertSame(self::FROZEN_NOW, $body['created_at']);
        $this->assertSame(['object' => $generation->snapshot], $body['data']);
    }

    public function test_the_signature_is_pe_008s_over_the_exact_body_at_now(): void
    {
        $generation = $this->givenAFakeGeneration();

        $this->sender()->deliver($generation, WebhookEventType::GenerationProcessing);

        $request = $this->fakeWebhookRequests()[0];
        $signature = $request->header(WebhookSignature::HEADER)[0];
        $timestamp = CarbonImmutable::now()->getTimestamp();

        $this->app->make(WebhookSignature::class)->verify($request->body(), $signature);
        $this->assertSame(
            sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $request->body(), self::FAKE_WEBHOOK_SECRET)),
            $signature,
        );
    }

    public function test_a_custom_base_url_is_used_without_a_doubled_slash(): void
    {
        config(['pixelforge.fake.webhook_base_url' => 'http://127.0.0.1:8080/']);
        Http::fake(['http://127.0.0.1:8080/*' => Http::response()]);
        $generation = $this->givenAFakeGeneration();

        $this->sender()->deliver($generation, WebhookEventType::GenerationProcessing);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8080/webhooks/pixelforge');
        $this->assertSame([], $this->fakeWebhookRequests());
    }

    /**
     * @param  Closure(Request): PromiseInterface  $responder
     */
    #[DataProvider('failedDeliveries')]
    public function test_a_failed_delivery_is_logged_once_and_never_thrown(Closure $responder, ?int $status): void
    {
        $generation = $this->givenAFakeGeneration();
        $this->answerFakeWebhooksWith($responder);

        $this->sender()->deliver($generation, WebhookEventType::GenerationProcessing);

        $requests = $this->fakeWebhookRequests();
        $this->assertCount(1, $requests);
        $warnings = array_values(array_filter($this->loggedMessages(), fn (MessageLogged $message): bool => $message->level === 'warning'));
        $this->assertCount(1, $warnings);
        $this->assertSame([
            'event_id' => self::decoded($requests[0])['id'],
            'type' => 'generation.processing',
            'provider_id' => $generation->id,
            'status' => $status,
        ], $warnings[0]->context);
        $this->assertNoLogCarries($requests[0]);
    }

    /**
     * @return array<string, array{Closure(Request): PromiseInterface, ?int}>
     */
    public static function failedDeliveries(): array
    {
        return [
            'a 500' => [fn (): PromiseInterface => Http::response('', 500), 500],
            'a 404' => [fn (): PromiseInterface => Http::response('', 404), 404],
            'no connection' => [Factory::failedConnection(), null],
        ];
    }

    public function test_a_delivered_webhook_logs_nothing(): void
    {
        $this->sender()->deliver($this->givenAFakeGeneration(), WebhookEventType::GenerationProcessing);

        $this->assertSame([], $this->loggedMessages());
    }

    /**
     * The secret was there at create and is gone at send: nothing is ever
     * signed with an empty key.
     */
    public function test_without_a_secret_nothing_is_sent(): void
    {
        $generation = $this->givenAFakeGeneration();
        config(['pixelforge.webhook_secret' => '']);

        try {
            $this->sender()->deliver($generation, WebhookEventType::GenerationProcessing);
            $this->fail('Expected the sender to refuse to sign without a secret.');
        } catch (WebhookSecretNotConfiguredException) {
            Http::assertNothingSent();
        }
    }

    private function givenAFakeGeneration(): FakePixelforgeGeneration
    {
        return $this->fakeGenerationRow($this->createThroughFake(self::lighthousePayload())->snapshot->id);
    }

    private function sender(): FakeWebhookSender
    {
        return $this->app->make(FakeWebhookSender::class);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(Request $request): array
    {
        $body = json_decode($request->body(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    private function assertNoLogCarries(Request $request): void
    {
        $signature = $request->header(WebhookSignature::HEADER)[0];

        foreach ($this->loggedMessages() as $message) {
            $logged = $message->message . json_encode($message->context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $this->assertStringNotContainsString(self::FAKE_WEBHOOK_SECRET, $logged);
            $this->assertStringNotContainsString($signature, $logged);
            $this->assertStringNotContainsString(self::LIGHTHOUSE_PROMPT, $logged);
        }
    }
}
