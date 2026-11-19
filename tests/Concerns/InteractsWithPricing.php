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
}
