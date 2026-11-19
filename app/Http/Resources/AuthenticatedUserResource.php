<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user as every page sees it: `auth.user` (resources/js/types/auth.ts).
 *
 * @property-read User $resource
 */
final class AuthenticatedUserResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, email: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
        ];
    }
}
