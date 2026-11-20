<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Pixelforge;

use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use App\Support\Pixelforge\Exceptions\RateBudgetExhaustedException;
use App\Support\Pixelforge\PixelforgeRequestBudget;
use App\Support\Pixelforge\ThrottledPixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\InteractsWithGenerationSnapshots;

/**
 * The budget arithmetic over an in-memory store, with a limit of 3 a minute.
 */
final class ThrottledPixelforgeClientTest extends TestCase
{
    use InteractsWithGenerationSnapshots;

    private const int LIMIT = 3;

    private int $innerCreates = 0;

    private int $innerConfiguredChecks = 0;

    private int $innerRetrieves = 0;

    private ThrottledPixelforgeClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-20T10:00:00Z');
        $this->client = new ThrottledPixelforgeClient(
            $this->recordingClient(),
            new PixelforgeRequestBudget(new RateLimiter(new Repository(new ArrayStore)), self::LIMIT),
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_calls_within_the_budget_reach_the_driver(): void
    {
        $this->createTimes(self::LIMIT);

        $this->assertSame(self::LIMIT, $this->innerCreates);
    }

    public function test_the_call_past_the_budget_sends_nothing_and_waits_for_the_window(): void
    {
        $this->createTimes(self::LIMIT);

        $this->assertSame(60, $this->exhaustedCreate()->retryAfterSeconds);
        $this->assertSame(self::LIMIT, $this->innerCreates);
    }

    public function test_the_wait_counts_down_with_the_window(): void
    {
        $this->createTimes(self::LIMIT);
        CarbonImmutable::setTestNow('2026-11-20T10:00:20Z');

        $this->assertSame(40, $this->exhaustedCreate()->retryAfterSeconds);
    }

    public function test_a_new_window_has_the_whole_budget_again(): void
    {
        $this->createTimes(self::LIMIT);
        $this->exhaustedCreate();
        CarbonImmutable::setTestNow('2026-11-20T10:01:00Z');

        $this->createTimes(self::LIMIT);

        $this->assertSame(2 * self::LIMIT, $this->innerCreates);
    }

    public function test_a_retrieve_takes_one_unit_of_the_same_budget(): void
    {
        $this->createTimes(self::LIMIT - 1);

        $this->client->retrieveGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

        $this->assertSame(1, $this->innerRetrieves);
        $this->exhaustedCreate();
    }

    public function test_a_retrieve_past_the_budget_reaches_nothing(): void
    {
        $this->createTimes(self::LIMIT);

        try {
            $this->client->retrieveGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');
            $this->fail('Expected the budget to be spent.');
        } catch (RateBudgetExhaustedException $exception) {
            $this->assertSame(60, $exception->retryAfterSeconds);
        }

        $this->assertSame(0, $this->innerRetrieves);
    }

    public function test_asking_whether_it_is_configured_takes_no_unit(): void
    {
        for ($check = 0; $check < 10; $check++) {
            $this->assertTrue($this->client->isConfigured());
        }

        $this->createTimes(self::LIMIT);

        $this->assertSame(10, $this->innerConfiguredChecks);
        $this->assertSame(self::LIMIT, $this->innerCreates);
    }

    public function test_the_wrapped_driver_is_exposed(): void
    {
        $this->assertInstanceOf(PixelforgeClient::class, $this->client->client);
        $this->assertNotInstanceOf(ThrottledPixelforgeClient::class, $this->client->client);
    }

    private function createTimes(int $times): void
    {
        for ($create = 0; $create < $times; $create++) {
            $this->client->createGeneration(self::payload());
        }
    }

    private function exhaustedCreate(): RateBudgetExhaustedException
    {
        try {
            $this->client->createGeneration(self::payload());
        } catch (RateBudgetExhaustedException $exception) {
            $this->assertSame(['error_code' => 'rate_budget_exhausted', 'retry_after_seconds' => $exception->retryAfterSeconds], $exception->context());

            return $exception;
        }

        $this->fail('Expected the budget to be spent.');
    }

    private static function payload(): CreateGenerationPayloadData
    {
        return new CreateGenerationPayloadData('01JC2Z8Q4N6V0M3H7X5T9R2B1K', 'A lighthouse at dusk', ImageSize::Square, ImageQuality::Medium, []);
    }

    private function recordingClient(): PixelforgeClient
    {
        $snapshot = GenerationSnapshot::from(self::pixelforgeFixture('generation-queued'));

        return new class($this, $snapshot) implements PixelforgeClient
        {
            public function __construct(private readonly ThrottledPixelforgeClientTest $test, private readonly GenerationSnapshot $snapshot) {}

            public function createGeneration(CreateGenerationPayloadData $payload): CreatedGenerationData
            {
                $this->test->recordCreate();

                return new CreatedGenerationData($this->snapshot, false);
            }

            public function retrieveGeneration(string $providerId): GenerationSnapshot
            {
                $this->test->recordRetrieve();

                return $this->snapshot;
            }

            public function isConfigured(): bool
            {
                $this->test->recordConfiguredCheck();

                return true;
            }
        };
    }

    public function recordCreate(): void
    {
        $this->innerCreates++;
    }

    public function recordConfiguredCheck(): void
    {
        $this->innerConfiguredChecks++;
    }

    public function recordRetrieve(): void
    {
        $this->innerRetrieves++;
    }
}
