<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Support\Pixelforge\DataTransferObjects\UsageReportData;
use App\Support\Pixelforge\Drivers\FakePixelforgeClient;
use App\Support\Pixelforge\Exceptions\DateRangeTooLongException;
use App\Support\Pixelforge\Exceptions\ValidationFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\TestCase;

/**
 * The fake answers `/v1/usage` as Pixelforge does (AC 3): at most 92 days, valid dates only.
 */
final class FakePixelforgeUsageTest extends TestCase
{
    use InteractsWithFakePixelforge;
    use RefreshDatabase;

    public function test_it_answers_its_own_succeeded_generations(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17T12:00:00Z'));
        $created = $this->createThroughFake(self::lighthousePayload(prompt: '[succeed] a calm harbour'));
        $this->runFakeLifecycle();

        $usage = $this->usage('2026-11-16', '2026-11-17');

        $this->assertSame(['2026-11-16', '2026-11-17'], array_keys($usage->days));
        $this->assertSame(0, $usage->days['2026-11-16']->generations);
        $this->assertSame(1, $usage->days['2026-11-17']->generations);
        $this->assertSame($this->fakeGenerationRow($created->snapshot->id)->usage['output_tokens'], $usage->total->tokens->outputTokens);
    }

    public function test_92_days_are_answered(): void
    {
        $usage = $this->usage('2026-08-20', '2026-11-19');

        $this->assertCount(92, $usage->days);
        $this->assertSame(0, $usage->total->cost->amount);
    }

    public function test_93_days_are_too_long(): void
    {
        try {
            $this->usage('2026-08-19', '2026-11-19');
            $this->fail('Expected date_range_too_long.');
        } catch (DateRangeTooLongException $exception) {
            $this->assertSame('invalid_request_error', $exception->type);
            $this->assertSame('end_date', $exception->param);
            $this->assertSame(422, $exception->status());
        }
    }

    #[DataProvider('invalidRanges')]
    public function test_an_invalid_range_fails_validation(string $startDate, string $endDate): void
    {
        try {
            $this->usage($startDate, $endDate);
            $this->fail('Expected validation_failed.');
        } catch (ValidationFailedException $exception) {
            $this->assertSame('start_date', $exception->param);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidRanges(): array
    {
        return [
            'a start after the end' => ['2026-11-19', '2026-11-18'],
            'a date that does not exist' => ['2026-02-30', '2026-03-02'],
            'an unpadded date' => ['2026-11-1', '2026-11-19'],
        ];
    }

    public function test_it_needs_no_webhook_secret_to_answer(): void
    {
        config(['pixelforge.webhook_secret' => null]);

        $this->assertCount(1, $this->usage('2026-11-19', '2026-11-19')->days);
    }

    private function usage(string $startDate, string $endDate): UsageReportData
    {
        return $this->app->make(FakePixelforgeClient::class)->retrieveUsage($startDate, $endDate);
    }
}
