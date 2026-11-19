<?php

declare(strict_types=1);

namespace App\Domain\Generation\QueryBuilders;

use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads of generations. `ownedBy()->newestFirst()` is History's list, which the
 * `(user_id, created_at, id)` index serves.
 *
 * @extends Builder<Generation>
 */
final class GenerationQueryBuilder extends Builder
{
    public function ownedBy(User $user): self
    {
        return $this->where('user_id', $user->getKey());
    }

    /**
     * Creation times are whole seconds, so the ULID, which grows with time, orders a tie.
     */
    public function newestFirst(): self
    {
        return $this->orderByDesc('created_at')->orderByDesc('id');
    }
}
