<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Live;

use App\Domain\Billing\Actions\EstimateGenerationCostAction;
use App\Domain\Billing\DataTransferObjects\GenerationCostEstimate;
use App\Domain\Billing\Enums\TokenType;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Drivers\HttpPixelforgeClient;
use App\Support\Pixelforge\Exceptions\ConcurrencyLimitExceededException;
use App\Support\Pixelforge\Exceptions\IdempotencyKeyReusedException;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use App\Support\Pixelforge\Exceptions\RateLimitExceededException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * The opt-in live smoke (D-12, D-58): our HTTP driver, the pinned pricing and
 * the estimator's assumptions against the real Pixelforge, with a dev key
 * only. It never runs in `make test`, `make check` or CI; `make live-smoke`
 * runs it. Nothing it prints carries the key or a request.
 */
#[Group('live')]
final class LivePixelforgeSmokeTest extends TestCase
{
    /**
     * Lowercase letters and hyphens are outside Crockford base32, so a smoke key can
     * never be a generation ULID.
     */
    private const string IDEMPOTENCY_KEY_PREFIX = 'live-smoke-';

    private const string ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}\z/';

    /**
     * 21 code points, the trailing space included.
     */
    private const string PROMPT_PREFIX = '[succeed] live smoke ';

    /**
     * U+1F305 SUNRISE: one code point, two UTF-16 units.
     */
    private const string SUNRISE = "\u{1F305}";

    private const int SUNRISE_COUNT = 30;

    /**
     * How often a create answered `503` or `429` is sent again, with the same key.
     */
    private const int CREATE_RETRIES = 3;

    private const int DEFAULT_RETRY_AFTER_SECONDS = 2;

    private const int MAX_RETRY_AFTER_SECONDS = 30;

    private HttpPixelforgeClient $client;

    private string $baseUrl;

    /**
     * A refusal fails the run before any client exists. Only the configured
     * base URL gets past `preventStrayRequests`, and every exchange is recorded.
     */
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->client = LiveSmokeGuard::fromRuntime($this->app)->client(config());
        } catch (RuntimeException $exception) {
            $this->fail($exception->getMessage());
        }

        $this->baseUrl = (string) config('pixelforge.base_url');

        Http::allowStrayRequests([$this->baseUrl . '/*']);
        Http::record();
    }

    /**
     * AC 3: the live document equals the pinned snapshot, with the live
     * variance fraction mapped back from the pinned integer percent.
     */
    public function test_the_live_pricing_equals_the_pinned_snapshot(): void
    {
        $response = Http::baseUrl($this->baseUrl)->acceptJson()->withoutRedirecting()->get('pricing');

        $this->assertSame(200, $response->status());

        $pricing = $response->json();
        $this->assertIsArray($pricing);

        $this->assertSame('pricing', $pricing['object'] ?? null);
        $this->assertSame('usd', $pricing['currency'] ?? null);
        $this->assertSame('per_1m_tokens', $pricing['unit'] ?? null);

        $this->assertSame(
            self::sortedByKey(['rates' => $this->pinnedRates(), 'tokens' => $this->pinnedTokens()]),
            self::sortedByKey(['rates' => $pricing['rates'] ?? null, 'tokens' => $pricing['tokens'] ?? null]),
            'Pixelforge\'s pricing drifted from config/pricing.php.',
        );
    }

    /**
     * AC 2 and 4: a fresh key, a code-point-heavy `[succeed]` prompt, and the
     * estimator's bounds for it.
     *
     * @return array{key: string, provider_id: string}
     */
    public function test_a_create_is_accepted_and_queued(): array
    {
        $key = self::IDEMPOTENCY_KEY_PREFIX . Str::uuid();
        $prompt = self::prompt();

        $this->assertDoesNotMatchRegularExpression(self::ULID_PATTERN, $key);
        $this->assertSame(51, mb_strlen($prompt, 'UTF-8'));
        $this->assertSame(81, intdiv(strlen(mb_convert_encoding($prompt, 'UTF-16LE', 'UTF-8')), 2));

        $estimate = $this->estimate($prompt);
        $text = $estimate->lines[TokenType::InputText->value]->tokens;

        $this->assertSame(11, $text->min);
        $this->assertSame(15, $text->max);
        $this->assertSame(17_525_000, $estimate->providerCost->max->amount);

        $created = $this->create(self::payload($key, $prompt));

        $this->assertSame(202, $this->lastResponse()->status());
        $this->assertFalse($created->replayed);
        $this->assertSame(GenerationStatus::Queued, $created->snapshot->status);
        $this->assertStringStartsWith('gen_', $created->snapshot->id);
        $this->assertSame($key, $created->snapshot->generationId);

        return ['key' => $key, 'provider_id' => $created->snapshot->id];
    }

    /**
     * AC 5: the same key and the same parameters replay the original generation.
     *
     * @param  array{key: string, provider_id: string}  $created
     */
    #[Depends('test_a_create_is_accepted_and_queued')]
    public function test_the_same_key_and_parameters_replay_the_generation(array $created): void
    {
        $replayed = $this->create(self::payload($created['key'], self::prompt()));

        $this->assertSame(202, $this->lastResponse()->status());
        $this->assertSame('true', $this->lastResponse()->header(HttpPixelforgeClient::REPLAYED_HEADER));
        $this->assertTrue($replayed->replayed);
        $this->assertSame($created['provider_id'], $replayed->snapshot->id);
    }

    /**
     * AC 6: the same key with another prompt is the typed `409`.
     *
     * @param  array{key: string, provider_id: string}  $created
     */
    #[Depends('test_a_create_is_accepted_and_queued')]
    public function test_the_same_key_with_another_prompt_is_refused(array $created): void
    {
        try {
            $this->create(self::payload($created['key'], self::PROMPT_PREFIX . 'with another prompt'));
            $this->fail('A reused key with another prompt was accepted.');
        } catch (IdempotencyKeyReusedException $exception) {
            $this->assertSame(409, $exception->status());
            $this->assertSame(409, $this->lastResponse()->status());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pinnedRates(): array
    {
        $rates = config('pricing.rates');
        $this->assertIsArray($rates);

        /** @var array<string, mixed> $rates */
        return $rates;
    }

    /**
     * The pinned token tables as the endpoint publishes them.
     *
     * @return array<string, mixed>
     */
    private function pinnedTokens(): array
    {
        $tokens = config('pricing.tokens');
        $this->assertIsArray($tokens);
        $this->assertIsInt($tokens['text_token_variance_percent'] ?? null);

        $tokens['text_token_variance'] = $tokens['text_token_variance_percent'] / 100;
        unset($tokens['text_token_variance_percent']);

        /** @var array<string, mixed> $tokens */
        return $tokens;
    }

    /**
     * Sends the create, and sends it again with the same key after a `503` or a
     * retryable `429`, which create nothing. Any other error is the test's.
     */
    private function create(CreateGenerationPayloadData $payload): CreatedGenerationData
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->client->createGeneration($payload);
            } catch (OverloadedException|RateLimitExceededException|ConcurrencyLimitExceededException $exception) {
                if ($attempt >= self::CREATE_RETRIES) {
                    throw $exception;
                }

                sleep(min($exception->retryAfterSeconds ?? self::DEFAULT_RETRY_AFTER_SECONDS, self::MAX_RETRY_AFTER_SECONDS));
            }
        }
    }

    /**
     * The last recorded answer; only its status, headers and body are ever read.
     */
    private function lastResponse(): Response
    {
        $pair = Http::recorded()->last();
        $this->assertIsArray($pair, 'Nothing was sent.');
        $this->assertInstanceOf(Response::class, $pair[1] ?? null);

        return $pair[1];
    }

    private function estimate(string $prompt): GenerationCostEstimate
    {
        return $this->app->make(EstimateGenerationCostAction::class)->execute($prompt, ImageQuality::Low, ImageSize::Square, 0);
    }

    /**
     * The key is also `metadata.generation_id`, as for every real generation.
     */
    private static function payload(string $key, string $prompt): CreateGenerationPayloadData
    {
        return new CreateGenerationPayloadData($key, $prompt, ImageSize::Square, ImageQuality::Low, []);
    }

    private static function prompt(): string
    {
        return self::PROMPT_PREFIX . str_repeat(self::SUNRISE, self::SUNRISE_COUNT);
    }

    /**
     * A copy with every level's keys sorted, so key order never matters and types always do.
     */
    private static function sortedByKey(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);

        return array_map(self::sortedByKey(...), $value);
    }
}
