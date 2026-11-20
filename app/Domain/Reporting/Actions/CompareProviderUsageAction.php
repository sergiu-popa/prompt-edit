<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Reporting\DataTransferObjects\UsageComparisonData;
use App\Domain\Reporting\Enums\UsageAvailability;
use App\Domain\Reporting\ValueObjects\ReportRange;
use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use App\Support\Pixelforge\Exceptions\DateRangeTooLongException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * The report's reconciliation (D-72, D-74): our recorded figures for the range beside
 * Pixelforge's `/v1/usage`. Whatever the contract throws, the report still renders our
 * figures, with a notice and one warning that carries only the failure's context; no
 * exception reaches the operator. Any other exception is a bug and surfaces.
 */
final readonly class CompareProviderUsageAction
{
    public const string UNAVAILABLE_LOG_MESSAGE = 'Pixelforge usage is unavailable; the report shows our recorded figures only.';

    public function __construct(
        private SumRecordedUsageAction $sumRecordedUsage,
        private FetchPixelforgeUsageAction $fetchPixelforgeUsage,
    ) {}

    /**
     * @throws NanodollarOverflowException
     * @throws UnexpectedValueException when a figure is unreadable, a bug
     */
    public function execute(ReportRange $range, CarbonImmutable $now): UsageComparisonData
    {
        $today = $now->utc()->format(ReportRange::DATE_FORMAT);
        $recorded = $this->sumRecordedUsage->execute($range);

        try {
            $usage = $this->fetchPixelforgeUsage->execute($range);
        } catch (DateRangeTooLongException $exception) {
            self::logUnavailable($range, $exception);

            return UsageComparisonData::withoutPixelforge($today, $recorded, UsageAvailability::RangeTooLong);
        } catch (ReportsPixelforgeContext $exception) {
            self::logUnavailable($range, $exception);

            return UsageComparisonData::withoutPixelforge($today, $recorded, UsageAvailability::Unavailable);
        }

        return UsageComparisonData::compared($today, $recorded, $usage);
    }

    /**
     * The context never holds the key, a header or a body.
     */
    private static function logUnavailable(ReportRange $range, ReportsPixelforgeContext $exception): void
    {
        Log::warning(self::UNAVAILABLE_LOG_MESSAGE, [...$range->toArray(), ...$exception->context()]);
    }
}
