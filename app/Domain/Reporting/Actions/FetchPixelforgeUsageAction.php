<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\ValueObjects\ReportRange;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use App\Support\Pixelforge\DataTransferObjects\UsageReportData;
use App\Support\Pixelforge\Exceptions\InvalidUsageReportException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository;

/**
 * Pixelforge's usage for the report's range, through the contract and so the shared budget
 * (D-74). A success is cached briefly per range on the default store, so reloads stay off
 * Pixelforge's 60 rpm; a failure is never cached, so the next load retries.
 */
final readonly class FetchPixelforgeUsageAction
{
    public function __construct(
        private PixelforgeClient $client,
        private Repository $cache,
        #[Config('pixelforge.usage.cache_seconds')]
        private int $cacheSeconds,
    ) {}

    /**
     * It is cached as the docs-shaped array and parsed again on a hit, because
     * `cache.serializable_classes` is false, so a cached object would come back incomplete.
     *
     * @throws ReportsPixelforgeContext for any failure the contract throws, never cached
     * @throws InvalidUsageReportException when a cached entry is unreadable, a bug
     */
    public function execute(ReportRange $range): UsageReportData
    {
        /** @var array<array-key, mixed> $report the store hands back what remember() stored */
        $report = $this->cache->remember(
            "pixelforge-usage:{$range->startDate()}:{$range->endDate()}",
            $this->cacheSeconds,
            fn (): array => $this->client->retrieveUsage($range->startDate(), $range->endDate())->toArray(),
        );

        return UsageReportData::from($report);
    }
}
