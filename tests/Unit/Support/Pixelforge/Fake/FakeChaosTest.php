<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Pixelforge\Fake;

use App\Support\Pixelforge\Fake\FakeChaos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Chaos strikes at its configured percent: never at 0, always at 100, and
 * each outcome reads only its own percent.
 */
final class FakeChaosTest extends TestCase
{
    private const int ROLLS = 1_000;

    #[DataProvider('outcomes')]
    public function test_at_zero_percent_it_never_strikes(string $method): void
    {
        $this->assertSame([false], $this->rolls(new FakeChaos(0, 0, 0), $method));
    }

    #[DataProvider('outcomes')]
    public function test_at_one_hundred_percent_it_always_strikes(string $method): void
    {
        $this->assertSame([true], $this->rolls(new FakeChaos(100, 100, 100), $method));
    }

    #[DataProvider('outcomes')]
    public function test_at_fifty_percent_it_strikes_sometimes(string $method): void
    {
        $results = $this->rolls(new FakeChaos(50, 50, 50), $method);
        sort($results);

        $this->assertSame([false, true], $results);
    }

    public function test_each_outcome_reads_only_its_own_percent(): void
    {
        $this->assertSame([true], $this->rolls(new FakeChaos(100, 0, 0), 'overloadsCreate'));
        $this->assertSame([false], $this->rolls(new FakeChaos(100, 0, 0), 'failsGeneration'));
        $this->assertSame([false], $this->rolls(new FakeChaos(100, 0, 0), 'duplicatesWebhooks'));

        $this->assertSame([true], $this->rolls(new FakeChaos(0, 100, 0), 'failsGeneration'));
        $this->assertSame([false], $this->rolls(new FakeChaos(0, 100, 0), 'overloadsCreate'));

        $this->assertSame([true], $this->rolls(new FakeChaos(0, 0, 100), 'duplicatesWebhooks'));
        $this->assertSame([false], $this->rolls(new FakeChaos(0, 0, 100), 'failsGeneration'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function outcomes(): array
    {
        return [
            'a 503 on create' => ['overloadsCreate'],
            'an internal_error failure' => ['failsGeneration'],
            'duplicated webhooks' => ['duplicatesWebhooks'],
        ];
    }

    /**
     * @return list<bool> the distinct results of many rolls
     */
    private function rolls(FakeChaos $chaos, string $method): array
    {
        $results = [];

        for ($roll = 0; $roll < self::ROLLS; $roll++) {
            $result = $chaos->{$method}();
            $this->assertIsBool($result);
            $results[] = $result;
        }

        return array_values(array_unique($results));
    }
}
