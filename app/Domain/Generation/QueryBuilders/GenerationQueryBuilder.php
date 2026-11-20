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
 * partial `generations_reconcile_poll_index` serves, and its unsubmitted step
 * `awaitingRedispatch()->dueForRecheck()->oldestCreatedFirst()`, which the
 * partial `generations_pending_index` serves.
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
     * Never confirmed by Pixelforge and still `pending`, and either handed over by the
     * submit job (PE-013 AC 23) or with its last attempt, else its creation, strictly
     * before the instant: its submit job stopped or died (AC 12). A row with a more
     * recent attempt is left to the job that may still be retrying it.
     */
    public function awaitingRedispatch(CarbonImmutable $attemptedBefore): self
    {
        $before = self::instant($attemptedBefore);

        return $this->where('status', GenerationStatus::Pending->value)
            ->whereNull('provider_id')
            ->where(fn (self $query): self => $query
                ->whereNotNull('submit_handed_over_at')
                ->orWhere(fn (self $attempted): self => $attempted
                    ->whereNotNull('submit_last_attempted_at')
                    ->where('submit_last_attempted_at', '<', $before))
                ->orWhere(fn (self $neverAttempted): self => $neverAttempted
                    ->whereNull('submit_last_attempted_at')
                    ->where('created_at', '<', $before)));
    }

    /**
     * The longest waiting first; the ULID orders a same-second tie.
     */
    public function oldestCreatedFirst(): self
    {
        return $this->orderBy('created_at')->orderBy('id');
    }

    /**
     * Not terminal, created at or before the instant, and not yet warned about by the
     * reconciler (D-56).
     */
    public function stuckUnwarned(CarbonImmutable $createdBefore): self
    {
        return $this->whereIn('status', [GenerationStatus::Pending->value, GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->where('created_at', '<=', self::instant($createdBefore))
            ->whereNull('reconcile_stuck_warned_at');
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
