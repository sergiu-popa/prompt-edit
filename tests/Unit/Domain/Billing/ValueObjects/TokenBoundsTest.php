<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\ValueObjects;

use App\Domain\Billing\ValueObjects\TokenBounds;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenBoundsTest extends TestCase
{
    public function test_an_exact_count_is_its_own_min_typical_and_max(): void
    {
        $bounds = TokenBounds::exact(1_530);

        $this->assertSame([1_530, 1_530, 1_530], [$bounds->min, $bounds->typical, $bounds->max]);
    }

    public function test_an_exact_count_may_be_zero(): void
    {
        $bounds = TokenBounds::exact(0);

        $this->assertSame([0, 0, 0], [$bounds->min, $bounds->typical, $bounds->max]);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function ranges(): array
    {
        return [
            'low reasoning' => [0, 500, 250],
            'medium reasoning' => [200, 2_000, 1_100],
            'high reasoning' => [1_000, 8_000, 4_500],
            'odd range rounds up' => [0, 499, 250],
            'text of 400 characters' => [85, 115, 100],
            'text of 1 character' => [0, 2, 1],
            'zero to one rounds up' => [0, 1, 1],
            'empty range' => [5, 5, 5],
            'zero range' => [0, 0, 0],
            'top of the int range' => [PHP_INT_MAX - 1, PHP_INT_MAX, PHP_INT_MAX],
        ];
    }

    #[DataProvider('ranges')]
    public function test_typical_is_the_midpoint_rounded_up_to_a_whole_token(int $min, int $max, int $expectedTypical): void
    {
        $bounds = TokenBounds::between($min, $max);

        $this->assertSame([$min, $expectedTypical, $max], [$bounds->min, $bounds->typical, $bounds->max]);
    }

    /**
     * @return array<string, array{callable(): TokenBounds}>
     */
    public static function invalidBounds(): array
    {
        return [
            'min above max' => [fn (): TokenBounds => TokenBounds::between(501, 500)],
            'negative min' => [fn (): TokenBounds => TokenBounds::between(-1, 5)],
            'negative exact count' => [fn (): TokenBounds => TokenBounds::exact(-1)],
        ];
    }

    /**
     * @param  callable(): TokenBounds  $build
     */
    #[DataProvider('invalidBounds')]
    public function test_rejects_negative_counts_and_inverted_ranges(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);

        $build();
    }
}
