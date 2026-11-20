<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Pixelforge;

use App\Support\Pixelforge\Exceptions\RateBudgetExhaustedException;
use App\Support\Pixelforge\PixelforgeRequestBudget;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * The account-wide budget (D-44) over an in-memory store, with a limit of 3 a minute.
 */
final class PixelforgeRequestBudgetTest extends TestCase
{
    private const int LIMIT = 3;

    private PixelforgeRequestBudget $budget;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-20T10:00:00Z');
        $this->budget = new PixelforgeRequestBudget(new RateLimiter(new Repository(new ArrayStore)), self::LIMIT);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_units_within_the_limit_are_taken(): void
    {
        $this->takeTimes(self::LIMIT);

        $this->addToAssertionCount(1);
    }

    public function test_the_unit_past_the_limit_is_refused_with_the_wait_for_the_window(): void
    {
        $this->takeTimes(self::LIMIT);
        CarbonImmutable::setTestNow('2026-11-20T10:00:20Z');

        $this->assertSame(40, $this->refusedTake()->retryAfterSeconds);
    }

    public function test_a_new_window_has_the_whole_limit_again(): void
    {
        $this->takeTimes(self::LIMIT);
        $this->refusedTake();
        CarbonImmutable::setTestNow('2026-11-20T10:01:00Z');

        $this->takeTimes(self::LIMIT);

        $this->assertSame(60, $this->refusedTake()->retryAfterSeconds);
    }

    public function test_remaining_counts_down_to_zero_and_never_below(): void
    {
        $this->assertSame(3, $this->budget->remaining());

        $this->budget->take();
        $this->assertSame(2, $this->budget->remaining());

        $this->takeTimes(2);
        $this->refusedTake();
        $this->assertSame(0, $this->budget->remaining());
    }

    public function test_remaining_is_the_whole_limit_again_in_a_new_window(): void
    {
        $this->takeTimes(self::LIMIT);
        CarbonImmutable::setTestNow('2026-11-20T10:01:00Z');

        $this->assertSame(self::LIMIT, $this->budget->remaining());
    }

    private function takeTimes(int $times): void
    {
        for ($unit = 0; $unit < $times; $unit++) {
            $this->budget->take();
        }
    }

    private function refusedTake(): RateBudgetExhaustedException
    {
        try {
            $this->budget->take();
        } catch (RateBudgetExhaustedException $exception) {
            return $exception;
        }

        $this->fail('Expected the budget to be spent.');
    }
}
