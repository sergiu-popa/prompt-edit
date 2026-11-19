<?php

declare(strict_types=1);

namespace Tests\Concerns;

use RuntimeException;

/**
 * Static helpers over the pinned pricing snapshot, so data providers can use them
 * without booting the application.
 */
trait InteractsWithPricing
{
    /**
     * The pinned `/v1/pricing` snapshot, read straight from config/pricing.php.
     *
     * @return array<string, mixed>
     */
    protected static function pinnedPricingSnapshot(): array
    {
        $snapshot = require dirname(__DIR__, 2) . '/config/pricing.php';

        if (! is_array($snapshot)) {
            throw new RuntimeException('config/pricing.php must return an array.');
        }

        return $snapshot;
    }

    /**
     * The pinned snapshot with some leaves replaced; keys are never removed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function pinnedPricingSnapshotWith(array $overrides): array
    {
        return array_replace_recursive(self::pinnedPricingSnapshot(), $overrides);
    }

    /**
     * The snapshot's rates as Pixelforge publishes them: USD per 1M tokens, as decimal strings.
     *
     * @return array<string, string>
     */
    protected static function publishedRates(): array
    {
        $rates = self::pinnedPricingSnapshot()['rates'] ?? null;

        if (! is_array($rates)) {
            throw new RuntimeException('The pinned pricing snapshot has no rates block.');
        }

        /** @var array<string, string> $rates */
        return $rates;
    }

    /**
     * The estimate fixture both the PHP and the TypeScript suites run against.
     *
     * @return array<string, mixed>
     */
    protected static function estimatesFixture(): array
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/estimates.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($fixture)) {
            throw new RuntimeException('tests/fixtures/estimates.json must decode to an object.');
        }

        return $fixture;
    }
}
