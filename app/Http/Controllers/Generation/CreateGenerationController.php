<?php

declare(strict_types=1);

namespace App\Http\Controllers\Generation;

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
 */
final class CreateGenerationController extends Controller
{
    public function __invoke(CreateGenerationRequest $request, CreateGenerationAction $createGeneration): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $generation = $createGeneration->execute($user, $request->toNewGenerationData());

        Inertia::flash('highlighted_generation_id', $generation->id);

        return to_route('generations.index');
    }
}
