<?php

declare(strict_types=1);

namespace App\Http\Controllers\Credits;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The fake Buy credits page (D-71): the configured packs in integer n$, and no
 * payment fields, because no payment is taken.
 */
final class ShowBuyCreditsController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Credits/Buy', [
            'packs_nanodollars' => array_values(config()->array('credits.packs_nanodollars')),
        ]);
    }
}
