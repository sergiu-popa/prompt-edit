<?php

declare(strict_types=1);

namespace App\Http\Controllers\Generation;

use App\Domain\Generation\Models\Generation;
use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationResource;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * History: the signed-in user's generations, newest first, a page at a time.
 * Inertia resolves the paginated collection to `{data, links, meta}`.
 */
final class ShowGenerationHistoryController extends Controller
{
    public const int PER_PAGE = 20;

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection(
                Generation::query()->ownedBy($user)->newestFirst()->paginate(self::PER_PAGE),
            ),
        ]);
    }
}
