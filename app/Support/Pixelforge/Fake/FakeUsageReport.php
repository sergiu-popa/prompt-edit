<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Fake;

use App\Domain\Billing\Enums\TokenType;
use App\Domain\Generation\Enums\GenerationStatus;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The fake's `GET /v1/usage` answer, from its own table (D-75): every succeeded generation
 * on the UTC date of its snapshot's `completed_at`, zero days included. Each costs Σ its
 * usage × its own rate strings ÷ 1M, in decimal arithmetic, never through our n$
 * calculator, so local parity independently checks our integer conversion and bucketing.
 */
final class FakeUsageReport
{
    private const int USD_SCALE = 9;

    private const int TOKENS_PER_RATE = 1_000_000;

    /**
     * The docs-shaped report for the inclusive range, rows ascending. The caller checks
     * the range first.
     *
     * @return array{object: string, start_date: string, end_date: string, data: list<array<string, int|string>>, total: array<string, int|string>}
     *
     * @throws InvalidArgumentException when a date isn't a `Y-m-d` date
     * @throws RoundingNecessaryException when a generation's cost isn't a whole nanodollar
     */
    public function build(string $startDate, string $endDate): array
    {
        $days = [];

        for ($date = self::date($startDate); $date->lessThanOrEqualTo(self::date($endDate)); $date = $date->addDay()) {
            $days[$date->format('Y-m-d')] = self::emptyRow();
        }

        foreach ($this->succeededBetween($startDate, $endDate) as $generation) {
            $date = substr(self::string($generation->snapshot['completed_at'] ?? null), 0, 10);
            $days[$date] = self::plus($days[$date], $generation);
        }

        $data = [];
        $total = self::emptyRow();

        foreach ($days as $date => $row) {
            $data[] = ['date' => $date, ...self::formatted($row)];
            $total = self::sum($total, $row);
        }

        return [
            'object' => 'usage',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'data' => $data,
            'total' => self::formatted($total),
        ];
    }

    /**
     * Filtered on the snapshot's text. That orders as time only because the fake alone
     * writes `completed_at`, always as a fixed-width Zulu string.
     *
     * @return iterable<FakePixelforgeGeneration>
     */
    private function succeededBetween(string $startDate, string $endDate): iterable
    {
        return FakePixelforgeGeneration::query()
            ->where('snapshot->status', GenerationStatus::Succeeded->value)
            ->where('snapshot->completed_at', '>=', $startDate . 'T00:00:00Z')
            ->where('snapshot->completed_at', '<', self::date($endDate)->addDay()->format('Y-m-d') . 'T00:00:00Z')
            ->lazyById();
    }

    /**
     * @param  array{generations: int, tokens: array<string, int>, cost: BigDecimal}  $row
     * @return array{generations: int, tokens: array<string, int>, cost: BigDecimal}
     *
     * @throws RoundingNecessaryException
     */
    private static function plus(array $row, FakePixelforgeGeneration $generation): array
    {
        $usage = $generation->snapshot['usage'] ?? null;
        $rates = $generation->snapshot['rates'] ?? null;
        $tokens = [];
        $cost = BigDecimal::zero();

        foreach (TokenType::keys() as $key) {
            $count = is_array($usage) && is_int($usage[$key] ?? null) ? $usage[$key] : throw new InvalidArgumentException("A succeeded fake generation has no {$key} count.");
            $tokens[$key] = $count;
            $cost = $cost->plus(BigDecimal::of(self::string(is_array($rates) ? ($rates[$key] ?? null) : null))->multipliedBy($count));
        }

        return self::sum($row, [
            'generations' => 1,
            'tokens' => $tokens,
            'cost' => $cost->dividedBy(self::TOKENS_PER_RATE, self::USD_SCALE, RoundingMode::Unnecessary),
        ]);
    }

    /**
     * @param  array{generations: int, tokens: array<string, int>, cost: BigDecimal}  $a
     * @param  array{generations: int, tokens: array<string, int>, cost: BigDecimal}  $b
     * @return array{generations: int, tokens: array<string, int>, cost: BigDecimal}
     */
    private static function sum(array $a, array $b): array
    {
        $tokens = [];

        foreach (TokenType::keys() as $key) {
            $tokens[$key] = ($a['tokens'][$key] ?? 0) + ($b['tokens'][$key] ?? 0);
        }

        return [
            'generations' => $a['generations'] + $b['generations'],
            'tokens' => $tokens,
            'cost' => $a['cost']->plus($b['cost']),
        ];
    }

    /**
     * @return array{generations: int, tokens: array<string, int>, cost: BigDecimal}
     */
    private static function emptyRow(): array
    {
        return ['generations' => 0, 'tokens' => array_fill_keys(TokenType::keys(), 0), 'cost' => BigDecimal::zero()];
    }

    /**
     * The docs' fields in the docs' order, the cost as an exact 9-decimal string.
     *
     * @param  array{generations: int, tokens: array<string, int>, cost: BigDecimal}  $row
     * @return array<string, int|string>
     */
    private static function formatted(array $row): array
    {
        return [
            'generations' => $row['generations'],
            ...$row['tokens'],
            'cost_usd' => (string) $row['cost']->toScale(self::USD_SCALE),
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function date(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Not a Y-m-d date: {$date}.");
        }

        return $parsed;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new InvalidArgumentException('A succeeded fake generation is missing a string field.');
    }
}
