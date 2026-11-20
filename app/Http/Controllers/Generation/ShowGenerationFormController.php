<?php

declare(strict_types=1);

namespace App\Http\Controllers\Generation;

use App\Domain\Billing\ValueObjects\PricingTable;
use App\Domain\Generation\ValueObjects\GenerationInputLimits;
use App\Http\Controllers\Controller;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Generate page, the signed-in home. The estimate runs in the browser from
 * the pricing prop; `can_submit` keeps Generate closed until Pixelforge is
 * configured (D-40), while the page still shows what an image would cost.
 */
final class ShowGenerationFormController extends Controller
{
    public function __invoke(PricingTable $pricing, PixelforgeClient $pixelforge): Response
    {
        return Inertia::render('Generations/Create', [
            'pricing' => $pricing->toArray(),
            'limits' => GenerationInputLimits::toArray(),
            'can_submit' => $pixelforge->isConfigured(),
        ]);
    }
}
