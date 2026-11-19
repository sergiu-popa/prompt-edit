<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Props every page receives (resources/js/types/inertia.d.ts).
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                /*
                 * Resolved, not passed as a resource: Inertia would wrap a bare
                 * JsonResource in `data`.
                 */
                'user' => function () use ($request): ?array {
                    $user = $request->user();

                    return $user instanceof User ? AuthenticatedUserResource::make($user)->resolve($request) : null;
                },
            ],
        ];
    }
}
