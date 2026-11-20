<?php

declare(strict_types=1);

namespace App\Http\Controllers\Generation;

use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Domain\Generation\Actions\CreateGenerationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Generation\CreateGenerationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Generate's submit: the request, the one create action, then History with the new
 * generation flashed, so it is highlighted once (a reload never sees the flash again).
 * A replay of the same form submission ends the same way, on the first generation.
 *
 * A generation the balance can't hold goes back to Generate with the `balance` error
 * and the amounts flashed as `credit_shortfall`, in integer n$ (D-68): the page words
 * them, so PHP never formats an amount. Back restores the error but not the flash, so
 * the error alone reads on its own.
 */
final class CreateGenerationController extends Controller
{
    /** The rate limiter the route throttles with, defined in AppServiceProvider (D-39). */
    public const string RATE_LIMITER = 'generation-submissions';

    public const string INSUFFICIENT_CREDITS_MESSAGE = "You don't have enough credit for this generation.";

    public function __invoke(CreateGenerationRequest $request, CreateGenerationAction $createGeneration): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $generation = $createGeneration->execute($user, $request->toNewGenerationData());
        } catch (InsufficientCreditsException $exception) {
            Inertia::flash('credit_shortfall', $exception->toArray());

            return back()->withErrors(['balance' => self::INSUFFICIENT_CREDITS_MESSAGE]);
        }

        Inertia::flash('highlighted_generation_id', $generation->id);

        return to_route('generations.index');
    }
}
