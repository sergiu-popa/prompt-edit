<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reporting;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Reporting\Actions\ShowOperatorReportAction;
use App\Domain\Reporting\ValueObjects\ReportRange;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator report, behind the `ABILITY` gate (D-63): everyone but an operator gets the
 * stock 403 page carrying `FORBIDDEN_MESSAGE`. Every amount reaches the page as integer n$
 * (D-03), and every date is a UTC date (D-65).
 */
final class ShowOperatorReportController extends Controller
{
    public const string ABILITY = 'view-operator-report';

    public const string FORBIDDEN_MESSAGE = "You don't have access to this page.";

    /**
     * @throws NanodollarOverflowException
     */
    public function __invoke(ShowOperatorReportAction $showOperatorReport): Response
    {
        $now = CarbonImmutable::now();
        $range = ReportRange::defaultFor($now);

        return Inertia::render('Reporting/Show', [
            'range' => $range->toArray(),
            'limits' => ReportRange::limitsFor($now),
            'report' => $showOperatorReport->execute($range)->toArray(),
        ]);
    }
}
