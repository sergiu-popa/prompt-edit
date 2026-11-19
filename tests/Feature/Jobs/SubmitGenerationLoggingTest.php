<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Jobs\SubmitGenerationJob;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;
use Throwable;

/**
 * Every submission log line names the generation and what happened to it,
 * and nothing a submission leaves behind (log, exception or queued job)
 * carries the API key, the prompt or the request body.
 */
final class SubmitGenerationLoggingTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    private const string GENERATION_ID = ':generation_id';

    private const string PROVIDER_ID = ':provider_id';

    /**
     * @param  array<string, mixed>  $context  `:generation_id` and `:provider_id` stand for the row's
     */
    #[DataProvider('submissionLines')]
    public function test_each_submission_line_carries_its_ids_and_outcome(string $answer, string $level, array $context): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();

        $this->submit($lighthouse, $answer);

        $lines = $this->submissionLinesFor($lighthouse);
        $this->assertCount(1, $lines);
        $this->assertSame($level, $lines[0]->level);
        $this->assertSame(self::resolved($context, $lighthouse), $lines[0]->context);
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function submissionLines(): array
    {
        return [
            'an applied 202' => ['202', 'info', [
                'generation_id' => self::GENERATION_ID,
                'provider_id' => self::PROVIDER_ID,
                'replayed' => false,
                'outcome' => 'applied',
            ]],
            'a replayed 202' => ['202 replayed', 'info', [
                'generation_id' => self::GENERATION_ID,
                'provider_id' => self::PROVIDER_ID,
                'replayed' => true,
                'outcome' => 'applied',
            ]],
            'a 400' => ['400', 'info', [
                'generation_id' => self::GENERATION_ID,
                'error_code' => 'content_policy_violation',
                'outcome' => 'applied',
            ]],
            'a 422' => ['422', 'error', [
                'generation_id' => self::GENERATION_ID,
                'error_code' => 'validation_failed',
                'outcome' => 'applied',
                'param' => 'size',
            ]],
            'a 409' => ['409', 'error', [
                'generation_id' => self::GENERATION_ID,
                'error_code' => 'idempotency_key_reused',
                'outcome' => 'applied',
            ]],
            'a 503, rethrown' => ['503', 'warning', [
                'generation_id' => self::GENERATION_ID,
                'error_code' => 'overloaded',
                'status' => 503,
                'retry_after_seconds' => 2,
            ]],
            'a read timeout, rethrown' => ['read timeout', 'warning', [
                'generation_id' => self::GENERATION_ID,
                'error_code' => 'ambiguous',
                'status' => null,
            ]],
            'no key, rethrown' => ['no key', 'warning', [
                'generation_id' => self::GENERATION_ID,
                'error_code' => 'not_configured',
            ]],
        ];
    }

    public function test_the_give_up_line_carries_the_ulid_and_the_failure(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();

        (new SubmitGenerationJob($lighthouse->id))->failed(new OverloadedException('api_error', 'Pixelforge is overloaded.', retryAfterSeconds: 2));

        $lines = $this->submissionLinesFor($lighthouse);
        $this->assertCount(1, $lines);
        $this->assertSame('error', $lines[0]->level);
        $this->assertSame([
            'generation_id' => $lighthouse->id,
            'exception' => OverloadedException::class,
            'error_code' => 'overloaded',
            'status' => 503,
            'retry_after_seconds' => 2,
        ], $lines[0]->context);
    }

    #[DataProvider('everyAnswer')]
    public function test_nothing_left_behind_carries_the_key_the_prompt_or_the_body(string $answer): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();

        $thrown = $this->submit($lighthouse, $answer);
        $job = new SubmitGenerationJob($lighthouse->id);
        $job->failed($thrown ?? new OverloadedException('api_error', 'Pixelforge is overloaded.'));

        $secrets = [self::PIXELFORGE_API_KEY, self::LIGHTHOUSE_PROMPT];

        foreach ($this->recordedCreateRequests() as $request) {
            $secrets[] = $request->body();
        }

        $leftBehind = [(string) $thrown, serialize($job)];

        foreach ($this->loggedMessages() as $logged) {
            $leftBehind[] = $logged->message;
            $leftBehind[] = (string) json_encode($logged->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        foreach ($leftBehind as $text) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text);
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everyAnswer(): array
    {
        return [
            'an applied 202' => ['202'],
            'a replayed 202' => ['202 replayed'],
            'a 400' => ['400'],
            'a 401' => ['401'],
            'a 422' => ['422'],
            'a 409' => ['409'],
            'a 503' => ['503'],
            'a read timeout' => ['read timeout'],
        ];
    }

    /**
     * One attempt against the answer. A rethrown failure is returned.
     */
    private function submit(Generation $generation, string $answer): ?Throwable
    {
        if ($answer === 'no key') {
            config(['pixelforge.api_key' => null]);
        }

        Http::fake(['*' => match ($answer) {
            '202', 'no key' => self::pixelforgeAccepted(self::generationObjectFor($generation, GenerationStatus::Queued)),
            '202 replayed' => self::pixelforgeAccepted(self::generationObjectFor($generation, GenerationStatus::Queued), replayed: 'true'),
            '400' => self::pixelforgeErrorFor(PixelforgeErrorCode::ContentPolicyViolation),
            '401' => self::pixelforgeErrorFor(PixelforgeErrorCode::InvalidApiKey),
            '422' => self::pixelforgeErrorFor(PixelforgeErrorCode::ValidationFailed, ['param' => 'size', 'errors' => ['size' => ['The selected size is invalid.']]]),
            '409' => self::pixelforgeErrorFor(PixelforgeErrorCode::IdempotencyKeyReused),
            '503' => self::pixelforgeErrorFor(PixelforgeErrorCode::Overloaded, headers: ['Retry-After' => '2']),
            'read timeout' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'),
            default => $this->fail(sprintf('Unknown answer %s.', $answer)),
        }]);

        try {
            $this->runSubmitJob($generation);
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * @return list<MessageLogged>
     */
    private function submissionLinesFor(Generation $generation): array
    {
        return array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $logged): bool => ($logged->context['generation_id'] ?? null) === $generation->id,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function resolved(array $context, Generation $generation): array
    {
        return array_map(fn (mixed $value): mixed => match ($value) {
            self::GENERATION_ID => $generation->id,
            self::PROVIDER_ID => 'gen_' . strtoupper($generation->id),
            default => $value,
        }, $context);
    }
}
