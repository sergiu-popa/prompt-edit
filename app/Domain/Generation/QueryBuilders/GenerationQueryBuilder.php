<?php

declare(strict_types=1);

namespace App\Domain\Generation\QueryBuilders;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads of generations. `ownedBy()->newestFirst()` is History's list, which the
 * `(user_id, created_at, id)` index serves. The reconciler's poll step reads
 * `staleAtPixelforge()->dueForRecheck()->oldestProviderUpdateFirst()`, which the
 * partial `generations_reconcile_poll_index` serves.
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

    /**
     * Confirmed by Pixelforge and non-terminal, with a last provider update (D-50)
     * strictly before the status's threshold. `updated_at` is never read: PE-013's
     * markers and the reconciler's own bookkeeping move it too. The status list
     * repeats the partial index's predicate, so the planner can use it.
     */
    public function staleAtPixelforge(CarbonImmutable $queuedBefore, CarbonImmutable $processingBefore): self
    {
        return $this->whereNotNull('provider_id')
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->where(fn (self $query): self => $query
                ->where(fn (self $queued): self => $queued
                    ->where('status', GenerationStatus::Queued->value)
                    ->where('last_provider_update_at', '<', self::instant($queuedBefore)))
                ->orWhere(fn (self $processing): self => $processing
                    ->where('status', GenerationStatus::Processing->value)
                    ->where('last_provider_update_at', '<', self::instant($processingBefore))));
    }

    /**
     * Never checked by the reconciler, or due again at or before now.
     */
    public function dueForRecheck(CarbonImmutable $now): self
    {
        return $this->where(fn (self $query): self => $query
            ->whereNull('reconcile_recheck_at')
            ->orWhere('reconcile_recheck_at', '<=', self::instant($now)));
    }

    /**
     * The longest without news first; the ULID orders a same-second tie.
     */
    public function oldestProviderUpdateFirst(): self
    {
        return $this->orderBy('last_provider_update_at')->orderBy('id');
    }

    /**
     * Bound with its offset, as the columns are written (D-10), so the database
     * session's timezone never shifts a comparison.
     */
    private static function instant(CarbonImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:sP');
    }
}
