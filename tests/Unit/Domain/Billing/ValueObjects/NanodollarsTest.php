<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\ValueObjects;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\Nanodollars;
use Brick\Math\BigInteger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

final class NanodollarsTest extends TestCase
{
    public function test_zero_is_a_named_amount_of_zero(): void
    {
        $this->assertSame(0, Nanodollars::zero()->amount);
        $this->assertTrue(Nanodollars::zero()->equals(new Nanodollars(0)));
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function additions(): array
    {
        return [
            'positive' => [100, 250, 350],
            'negative result' => [100, -250, -150],
            'zero' => [0, 0, 0],
            'up to PHP_INT_MAX' => [PHP_INT_MAX - 1, 1, PHP_INT_MAX],
            'down to PHP_INT_MIN' => [PHP_INT_MIN + 1, -1, PHP_INT_MIN],
        ];
    }

    #[DataProvider('additions')]
    public function test_plus_is_exact(int $left, int $right, int $expected): void
    {
        $this->assertSame($expected, (new Nanodollars($left))->plus(new Nanodollars($right))->amount);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function subtractions(): array
    {
        return [
            'below zero' => [100, 250, -150],
            'positive' => [250, 100, 150],
            'to zero' => [373_875_000, 373_875_000, 0],
            'down to PHP_INT_MIN' => [-1, PHP_INT_MAX, PHP_INT_MIN],
        ];
    }

    #[DataProvider('subtractions')]
    public function test_minus_is_exact(int $left, int $right, int $expected): void
    {
        $this->assertSame($expected, (new Nanodollars($left))->minus(new Nanodollars($right))->amount);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function multiplications(): array
    {
        return [
            'tokens times rate' => [40_000, 6_450, 258_000_000],
            'by zero' => [PHP_INT_MAX, 0, 0],
            'negative' => [-150, 3, -450],
        ];
    }

    #[DataProvider('multiplications')]
    public function test_multiplied_by_is_exact(int $amount, int $factor, int $expected): void
    {
        $this->assertSame($expected, (new Nanodollars($amount))->multipliedBy($factor)->amount);
    }

    public function test_equal_amounts_compare_equal(): void
    {
        $this->assertTrue((new Nanodollars(-150))->equals(new Nanodollars(-150)));
        $this->assertFalse((new Nanodollars(150))->equals(new Nanodollars(-150)));
        $this->assertSame(0, (new Nanodollars(373_875_000))->compareTo(new Nanodollars(373_875_000)));
    }

    public function test_compare_to_orders_amounts(): void
    {
        $this->assertSame(-1, (new Nanodollars(-150))->compareTo(new Nanodollars(100)));
        $this->assertSame(1, (new Nanodollars(100))->compareTo(new Nanodollars(-150)));
        $this->assertSame(1, (new Nanodollars(PHP_INT_MAX))->compareTo(new Nanodollars(PHP_INT_MIN)));
    }

    public function test_from_big_integer_accepts_the_signed_64_bit_range(): void
    {
        $this->assertSame(PHP_INT_MAX, Nanodollars::fromBigInteger(BigInteger::of(PHP_INT_MAX))->amount);
        $this->assertSame(PHP_INT_MIN, Nanodollars::fromBigInteger(BigInteger::of(PHP_INT_MIN))->amount);
    }

    public function test_from_big_integer_rejects_values_outside_the_signed_64_bit_range(): void
    {
        $this->expectException(NanodollarOverflowException::class);
        $this->expectExceptionMessage('9223372036854775808');

        Nanodollars::fromBigInteger(BigInteger::of(PHP_INT_MAX)->plus(1));
    }

    /**
     * @return array<string, array{callable(): Nanodollars}>
     */
    public static function overflowingOperations(): array
    {
        return [
            'PHP_INT_MAX + 1' => [fn (): Nanodollars => (new Nanodollars(PHP_INT_MAX))->plus(new Nanodollars(1))],
            'PHP_INT_MIN - 1' => [fn (): Nanodollars => (new Nanodollars(PHP_INT_MIN))->minus(new Nanodollars(1))],
            'PHP_INT_MIN + -1' => [fn (): Nanodollars => (new Nanodollars(PHP_INT_MIN))->plus(new Nanodollars(-1))],
            'PHP_INT_MAX - -1' => [fn (): Nanodollars => (new Nanodollars(PHP_INT_MAX))->minus(new Nanodollars(-1))],
            'PHP_INT_MAX x 2' => [fn (): Nanodollars => (new Nanodollars(PHP_INT_MAX))->multipliedBy(2)],
            'PHP_INT_MIN x -1' => [fn (): Nanodollars => (new Nanodollars(PHP_INT_MIN))->multipliedBy(-1)],
        ];
    }

    /**
     * @param  callable(): Nanodollars  $operation
     */
    #[DataProvider('overflowingOperations')]
    public function test_overflowing_operations_throw_instead_of_becoming_floats(callable $operation): void
    {
        $this->expectException(NanodollarOverflowException::class);

        $operation();
    }

    /**
     * @return array<string, array{callable(mixed): mixed}>
     */
    public static function floatRejectingEntryPoints(): array
    {
        return [
            'constructor' => [fn (mixed $value): mixed => new Nanodollars($value)],
            'multipliedBy' => [fn (mixed $value): mixed => Nanodollars::zero()->multipliedBy($value)],
        ];
    }

    /**
     * @param  callable(mixed): mixed  $entryPoint
     */
    #[DataProvider('floatRejectingEntryPoints')]
    public function test_floats_are_rejected_with_a_type_error(callable $entryPoint): void
    {
        $this->expectException(TypeError::class);

        $entryPoint(0.1);
    }
}
