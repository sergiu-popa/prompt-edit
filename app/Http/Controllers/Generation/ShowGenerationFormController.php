<?php

declare(strict_types=1);

namespace App\Http\Controllers\Generation;

use App\Domain\Billing\ValueObjects\PricingTable;
use App\Domain\Generation\ValueObjects\GenerationInputLimits;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Generate page, the signed-in home. The estimate runs in the browser from
 * the pricing prop, so the page needs no other data.
 */
final class ShowGenerationFormController extends Controller
{
    public function __invoke(PricingTable $pricing): Response
    {
        return Inertia::render('Generations/Create', [
            'pricing' => $pricing->toArray(),
            'limits' => GenerationInputLimits::toArray(),
        ]);
    }
}
