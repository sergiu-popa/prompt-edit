<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator report, behind the `ABILITY` gate (D-63): everyone but an operator gets the
 * stock 403 page carrying `FORBIDDEN_MESSAGE`.
 */
final class ShowOperatorReportController extends Controller
{
    public const string ABILITY = 'view-operator-report';

    public const string FORBIDDEN_MESSAGE = "You don't have access to this page.";

    public function __invoke(): Response
    {
        return Inertia::render('Reporting/Show');
    }
}
