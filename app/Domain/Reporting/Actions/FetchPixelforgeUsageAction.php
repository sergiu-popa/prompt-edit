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
     * A cached entry that no longer reads, such as one stored by a deploy whose shape
     * differed, is dropped and fetched again, so it never fails the page.
     *
     * @throws ReportsPixelforgeContext for any failure the contract throws, never cached
     */
    public function execute(ReportRange $range): UsageReportData
    {
        $key = "pixelforge-usage:{$range->startDate()}:{$range->endDate()}";

        try {
            return $this->remembered($key, $range);
        } catch (InvalidUsageReportException) {
            $this->cache->forget($key);

            return $this->remembered($key, $range);
        }
    }

    /**
     * It is cached as the docs-shaped array and parsed again on a hit, because
     * `cache.serializable_classes` is false, so a cached object would come back incomplete.
     *
     * @throws ReportsPixelforgeContext
     * @throws InvalidUsageReportException when the cached entry is unreadable
     */
    private function remembered(string $key, ReportRange $range): UsageReportData
    {
        /** @var mixed $report a hit hands back whatever the store holds, maybe an earlier deploy's */
        $report = $this->cache->remember(
            $key,
            $this->cacheSeconds,
            fn (): array => $this->client->retrieveUsage($range->startDate(), $range->endDate())->toArray(),
        );

        if (! is_array($report)) {
            throw InvalidUsageReportException::invalidField('cached report', 'an object', $report);
        }

        $usage = UsageReportData::from($report);

        if ($usage->startDate !== $range->startDate() || $usage->endDate !== $range->endDate()) {
            throw InvalidUsageReportException::invalidField('range', "{$range->startDate()} to {$range->endDate()}", "{$usage->startDate} to {$usage->endDate}");
        }

        return $usage;
    }
}
