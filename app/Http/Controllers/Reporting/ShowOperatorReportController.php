<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reporting;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Reporting\Actions\CompareProviderUsageAction;
use App\Domain\Reporting\Actions\ShowOperatorReportAction;
use App\Domain\Reporting\ValueObjects\ReportRange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\ShowOperatorReportRequest;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

/**
 * The operator report, behind the `ABILITY` gate (D-63): everyone but an operator gets the
 * stock 403 page carrying `FORBIDDEN_MESSAGE`. Every amount reaches the page as integer n$
 * (D-03), and every date is a UTC date (D-65). Beside the report, the same range is
 * reconciled with Pixelforge's `/v1/usage` (D-72), which never fails the page (D-74).
 */
final class ShowOperatorReportController extends Controller
{
    public const string ABILITY = 'view-operator-report';

    public const string FORBIDDEN_MESSAGE = "You don't have access to this page.";

    /**
     * @throws NanodollarOverflowException
     * @throws UnexpectedValueException when a recorded figure is unreadable, a bug
     */
    public function __invoke(
        ShowOperatorReportRequest $request,
        ShowOperatorReportAction $showOperatorReport,
        CompareProviderUsageAction $compareProviderUsage,
    ): Response {
        $range = $request->range();
        $now = CarbonImmutable::now();

        return Inertia::render('Reporting/Show', [
            'range' => $range->toArray(),
            'limits' => ReportRange::limitsFor($now),
            'report' => $showOperatorReport->execute($range)->toArray(),
            'usage' => $compareProviderUsage->execute($range, $now)->toArray(),
        ]);
    }
}
