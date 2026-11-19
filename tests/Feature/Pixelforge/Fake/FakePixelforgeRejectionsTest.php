<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\ContentPolicyViolationException;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Fake\FakePixelforgeGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\TestCase;

/**
 * `[blocked]` and `[overloaded]` refuse a create before anything is stored,
 * so a retry with the same key is refused the same way.
 */
final class FakePixelforgeRejectionsTest extends TestCase
{
    use InteractsWithFakePixelforge;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    #[DataProvider('blockedSpellings')]
    public function test_a_blocked_prompt_is_refused_and_creates_nothing(string $keyword): void
    {
        $payload = self::lighthousePayload(prompt: "A lighthouse {$keyword} on a cliff at dawn");

        foreach (['first attempt', 'retry'] as $attempt) {
            $exception = $this->refusal($payload);

            $this->assertInstanceOf(ContentPolicyViolationException::class, $exception, $attempt);
            $this->assertSame(400, $exception->status());
            $this->assertSame('invalid_request_error', $exception->type);
            $this->assertSame('prompt', $exception->param);
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertNothingCreated();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockedSpellings(): array
    {
        return [
            'lower case' => ['[blocked]'],
            'upper case' => ['[BLOCKED]'],
            'mixed case' => ['[Blocked]'],
        ];
    }

    public function test_an_overloaded_prompt_is_refused_with_retry_after_and_creates_nothing(): void
    {
        $payload = self::lighthousePayload(prompt: 'A lighthouse [overloaded]');

        foreach (['first attempt', 'retry'] as $attempt) {
            $exception = $this->refusal($payload);

            $this->assertInstanceOf(OverloadedException::class, $exception, $attempt);
            $this->assertSame(503, $exception->status());
            $this->assertSame('api_error', $exception->type);
            $this->assertSame(2, $exception->retryAfterSeconds);
        }

        $this->assertNothingCreated();
    }

    public function test_blocked_wins_over_overloaded(): void
    {
        $exception = $this->refusal(self::lighthousePayload(prompt: '[overloaded] A lighthouse [blocked]'));

        $this->assertSame(PixelforgeErrorCode::ContentPolicyViolation, $exception->errorCode());
        $this->assertNothingCreated();
    }

    private function refusal(CreateGenerationPayloadData $payload): PixelforgeApiException
    {
        try {
            $this->createThroughFake($payload);
        } catch (PixelforgeApiException $exception) {
            return $exception;
        }

        $this->fail('Expected the fake to refuse the create.');
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, FakePixelforgeGeneration::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
