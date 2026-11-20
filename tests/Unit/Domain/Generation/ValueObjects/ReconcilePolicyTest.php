<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\ValueObjects;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\ValueObjects\ReconcilePolicy;
use Carbon\CarbonImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reconciler's rules with production's bounds and a fixed now: when a row is
 * stale, and when it is next checked (D-50).
 */
final class ReconcilePolicyTest extends TestCase
{
    private const string NOW = '2026-11-20T12:00:00Z';

    public function test_a_queued_row_is_stale_once_its_last_update_is_older_than_2_minutes(): void
    {
        $this->assertSame('2026-11-20T11:58:00Z', self::policy()->staleBefore(GenerationStatus::Queued, self::now())->toIso8601ZuluString());
    }

    public function test_a_processing_row_is_stale_once_its_last_update_is_older_than_3_minutes(): void
    {
        $this->assertSame('2026-11-20T11:57:00Z', self::policy()->staleBefore(GenerationStatus::Processing, self::now())->toIso8601ZuluString());
    }

    #[DataProvider('statusesNeverPolled')]
    public function test_only_queued_and_processing_rows_have_a_staleness_threshold(GenerationStatus $status): void
    {
        $this->expectException(LogicException::class);

        self::policy()->staleBefore($status, self::now());
    }

    /**
     * @return array<string, array{GenerationStatus}>
     */
    public static function statusesNeverPolled(): array
    {
        return [
            'pending' => [GenerationStatus::Pending],
            'succeeded' => [GenerationStatus::Succeeded],
            'failed' => [GenerationStatus::Failed],
        ];
    }

    /**
     * Age ÷ 4, between the recheck and the max recheck: 1 min until 4 min of age,
     * then growing to 15 min at 1 h.
     */
    #[DataProvider('cadence')]
    public function test_the_next_check_waits_a_quarter_of_the_rows_age_within_the_bounds(int $ageSeconds, int $waitSeconds): void
    {
        $recheckAt = self::policy()->recheckAt(self::now()->subSeconds($ageSeconds), self::now());

        $this->assertSame($waitSeconds, (int) self::now()->diffInSeconds($recheckAt));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function cadence(): array
    {
        return [
            'a new row' => [0, 60],
            '3 min old' => [180, 60],
            '4 min old' => [240, 60],
            '8 min old' => [480, 120],
            '1 h old' => [3_600, 900],
            '2 h old' => [7_200, 900],
            'a day old' => [86_400, 900],
        ];
    }

    /**
     * A row created 2 h ago is checked at most 4 times in the next hour (AC 6).
     */
    public function test_a_row_stuck_for_2_hours_is_checked_at_most_4_times_an_hour(): void
    {
        $createdAt = self::now()->subHours(2);
        $checks = 0;
        $checkAt = self::now();

        while ($checkAt->lessThan(self::now()->addHour())) {
            $checks++;
            $checkAt = self::policy()->recheckAt($createdAt, $checkAt);
        }

        $this->assertSame(4, $checks);
    }

    public function test_a_max_recheck_below_the_recheck_means_the_recheck(): void
    {
        $policy = self::policy(recheckSeconds: 120, maxRecheckSeconds: 30);

        $this->assertSame(120, (int) self::now()->diffInSeconds($policy->recheckAt(self::now()->subDay(), self::now())));
    }

    public function test_a_creation_time_after_now_waits_the_recheck(): void
    {
        $this->assertSame(60, (int) self::now()->diffInSeconds(self::policy()->recheckAt(self::now()->addMinute(), self::now())));
    }

    public function test_the_batch_size_is_the_configured_one(): void
    {
        $this->assertSame(20, self::policy()->batchSize());
        $this->assertSame(3, self::policy(batchSize: 3)->batchSize());
    }

    /**
     * AC 17: a poll is sent only while more than the reserve is left, so 12 units
     * left give 2 polls.
     *
     * @return array<string, array{int, int, bool}>
     */
    public static function reserve(): array
    {
        return [
            'reserve 10, 12 left' => [10, 12, true],
            'reserve 10, 11 left' => [10, 11, true],
            'reserve 10, 10 left' => [10, 10, false],
            'reserve 10, none left' => [10, 0, false],
            'reserve 0, 1 left' => [0, 1, true],
            'reserve 0, none left' => [0, 0, false],
        ];
    }

    #[DataProvider('reserve')]
    public function test_a_poll_leaves_the_reserve_to_submits(int $reserve, int $remaining, bool $allowed): void
    {
        $this->assertSame($allowed, self::policy(submitReserve: $reserve)->allowsPoll($remaining));
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW);
    }

    private static function policy(
        int $queuedAfterSeconds = 120,
        int $processingAfterSeconds = 180,
        int $recheckSeconds = 60,
        int $maxRecheckSeconds = 900,
        int $batchSize = 20,
        int $submitReserve = 10,
    ): ReconcilePolicy {
        return new ReconcilePolicy(
            queuedAfterSeconds: $queuedAfterSeconds,
            processingAfterSeconds: $processingAfterSeconds,
            recheckSeconds: $recheckSeconds,
            maxRecheckSeconds: $maxRecheckSeconds,
            batchSize: $batchSize,
            submitReserve: $submitReserve,
        );
    }
}
