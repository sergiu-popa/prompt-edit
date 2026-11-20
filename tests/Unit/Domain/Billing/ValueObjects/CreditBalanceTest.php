<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\ValueObjects;

use App\Domain\Billing\ValueObjects\CreditBalance;
use App\Domain\Billing\ValueObjects\Nanodollars;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Runs every case of tests/fixtures/credit-shortfalls.json, the contract the
 * TypeScript `creditShortfall` is held to as well (D-69).
 *
 * @phpstan-type ShortfallCase array{name: string, available: int, required: int, shortfall: int, display: string}
 */
final class CreditBalanceTest extends TestCase
{
    /**
     * @return array<string, array{ShortfallCase}>
     */
    public static function fixtureCases(): array
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/fixtures/credit-shortfalls.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($fixture) || ! is_array($fixture['cases'] ?? null)) {
            throw new RuntimeException('tests/fixtures/credit-shortfalls.json must hold a list of cases.');
        }

        /** @var list<ShortfallCase> $cases */
        $cases = $fixture['cases'];
        $named = [];

        foreach ($cases as $case) {
            $named[$case['name']] = [$case];
        }

        return $named;
    }

    /**
     * @param  ShortfallCase  $case
     */
    #[DataProvider('fixtureCases')]
    public function test_the_shortfall_matches_the_shared_fixture(array $case): void
    {
        $balance = new CreditBalance(new Nanodollars($case['available']), Nanodollars::zero(), 0);

        $this->assertSame($case['shortfall'], $balance->shortfallFor(new Nanodollars($case['required']))->amount);
    }

    public function test_it_becomes_the_shared_props_integer_shape(): void
    {
        $balance = new CreditBalance(new Nanodollars(913_687_500), new Nanodollars(86_312_500), 1);

        $this->assertSame(
            ['available_nanodollars' => 913_687_500, 'reserved_nanodollars' => 86_312_500, 'running_generations' => 1],
            $balance->toArray(),
        );
    }
}
