<?php

declare(strict_types=1);

namespace App\Http\Controllers\Credits;

use App\Domain\Billing\Actions\PurchaseCreditsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Credits\PurchaseCreditsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Buy credits' Add: the request, the one purchase action, then the Buy credits page
 * with the amount the stored purchase added flashed, in integer n$. A replay of the
 * same token ends the same way, with the first purchase's amount.
 */
final class PurchaseCreditsController extends Controller
{
    public function __invoke(PurchaseCreditsRequest $request, PurchaseCreditsAction $purchaseCredits): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $purchase = $purchaseCredits->execute($user, $request->toPack(), $request->purchaseToken());

        Inertia::flash('purchased_nanodollars', $purchase->amount_nanodollars);

        return to_route('credits.index');
    }
}
