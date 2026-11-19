<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Actions\CalculateProviderCostAction;
use App\Domain\Billing\Actions\CalculateUserChargeAction;
use App\Domain\Billing\Actions\SettleGenerationCreditsAction;
use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Billing\ValueObjects\TokenRates;
use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Exceptions\ConcurrentGenerationTransitionException;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use App\Support\Pixelforge\Exceptions\InvalidGenerationSnapshotException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single path that changes a generation's status (D-06): create and replay
 * responses, webhooks and the poller bring a Pixelforge snapshot, and submit
 * failures bring a local failure. Status only moves to a higher rank, terminal
 * statuses never change, and only an `Applied` outcome writes.
 *
 * Money moves with the status, in the same transaction: a succeeded snapshot
 * is priced from its own usage and rates before anything is written, then the
 * hold is settled against the actual charge.
 */
final class TransitionGenerationAction
{
    public function __construct(
        private readonly CalculateProviderCostAction $calculateProviderCost,
        private readonly CalculateUserChargeAction $calculateUserCharge,
        private readonly SettleGenerationCreditsAction $settleGenerationCredits,
    ) {}

    /**
     * The given model is used only for its key and is never mutated; callers
     * refresh it.
     *
     * @throws ConcurrentGenerationTransitionException when the row changed under the lock
     * @throws InvalidRateException when a succeeded snapshot's rate is not a whole n$ per token
     * @throws NanodollarOverflowException
     */
    public function execute(Generation $generation, GenerationSnapshot|LocalGenerationFailureData $change): GenerationTransitionOutcome
    {
        return DB::transaction(function () use ($generation, $change): GenerationTransitionOutcome {
            $locked = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            [$outcome, $conflict] = $change instanceof GenerationSnapshot
                ? $this->classifySnapshot($locked, $change)
                : $this->classifyLocalFailure($locked->status);

            if ($conflict !== null) {
                $this->logConflict($conflict, $locked, $change, $outcome);
            }

            if ($outcome === GenerationTransitionOutcome::Applied) {
                $this->apply($locked, $change);
            }

            return $outcome;
        });
    }

    /**
     * Identity is checked before any rank, so a snapshot of another generation
     * never touches this one, whatever its status.
     *
     * @return array{GenerationTransitionOutcome, ?string} the outcome, and the log message of a conflict
     */
    private function classifySnapshot(Generation $locked, GenerationSnapshot $snapshot): array
    {
        $identityConflict = match (true) {
            $snapshot->generationId === null => 'Refused a Pixelforge snapshot without our generation id.',
            $snapshot->generationId !== $locked->id => 'Refused a Pixelforge snapshot of another generation.',
            $locked->provider_id !== null && $snapshot->id !== $locked->provider_id => 'Refused a Pixelforge snapshot whose id differs from the stored provider id.',
            default => null,
        };

        if ($identityConflict !== null) {
            return [GenerationTransitionOutcome::RefusedConflict, $identityConflict];
        }

        $current = $locked->status;
        $incoming = $snapshot->status;

        if ($current->isTerminal()) {
            return match (true) {
                $incoming === $current => [GenerationTransitionOutcome::IgnoredDuplicate, null],
                $incoming->isTerminal() => [
                    GenerationTransitionOutcome::IgnoredConflict,
                    'Ignored a Pixelforge snapshot whose terminal status contradicts the generation\'s.',
                ],
                default => [GenerationTransitionOutcome::IgnoredTerminal, null],
            };
        }

        return match ($incoming->rank() <=> $current->rank()) {
            1 => [GenerationTransitionOutcome::Applied, null],
            0 => [GenerationTransitionOutcome::IgnoredDuplicate, null],
            -1 => [GenerationTransitionOutcome::IgnoredStale, null],
        };
    }

    /**
     * Only Pixelforge's own terminal status may end a generation it accepted,
     * because it may bill for it.
     *
     * @return array{GenerationTransitionOutcome, ?string} the outcome, and the log message of a conflict
     */
    private function classifyLocalFailure(GenerationStatus $current): array
    {
        return match ($current) {
            GenerationStatus::Pending => [GenerationTransitionOutcome::Applied, null],
            GenerationStatus::Queued, GenerationStatus::Processing => [
                GenerationTransitionOutcome::RefusedConflict,
                'Refused to fail a generation locally after Pixelforge accepted it.',
            ],
            GenerationStatus::Succeeded, GenerationStatus::Failed => [GenerationTransitionOutcome::IgnoredTerminal, null],
        };
    }

    private function logConflict(
        string $message,
        Generation $locked,
        GenerationSnapshot|LocalGenerationFailureData $change,
        GenerationTransitionOutcome $outcome,
    ): void {
        $isSnapshot = $change instanceof GenerationSnapshot;

        Log::error($message, [
            'generation_id' => $locked->id,
            'status' => $locked->status->value,
            'provider_id' => $locked->provider_id,
            'incoming_status' => $isSnapshot ? $change->status->value : GenerationStatus::Failed->value,
            'incoming_provider_id' => $isSnapshot ? $change->id : null,
            'outcome' => $outcome->value,
        ]);
    }

    /**
     * A succeeded snapshot is priced first, so a snapshot that cannot be priced
     * exactly writes nothing.
     *
     * @throws ConcurrentGenerationTransitionException when the row changed under the lock
     * @throws InvalidRateException
     * @throws NanodollarOverflowException
     */
    private function apply(Generation $locked, GenerationSnapshot|LocalGenerationFailureData $change): void
    {
        if ($change instanceof LocalGenerationFailureData) {
            $this->write($locked, $this->localFailureAttributes($change));

            return;
        }

        if ($change->status !== GenerationStatus::Succeeded) {
            $this->write($locked, $this->snapshotAttributes($change));

            return;
        }

        [$providerCost, $userCharge] = $this->price($change);

        $this->write($locked, [
            ...$this->snapshotAttributes($change),
            'provider_cost_nanodollars' => $providerCost->amount,
            'user_charge_nanodollars' => $userCharge->amount,
        ]);
        $this->settleGenerationCredits->execute($locked->id, $userCharge);
    }

    /**
     * What Pixelforge bills for the generation, from the snapshot's own usage
     * and rates (never the pinned table), and that cost with the markup.
     *
     * @return array{Nanodollars, Nanodollars} the provider cost and the user charge
     *
     * @throws InvalidRateException
     * @throws NanodollarOverflowException
     */
    private function price(GenerationSnapshot $snapshot): array
    {
        $usage = $snapshot->usage ?? throw InvalidGenerationSnapshotException::incompleteSuccess('usage');
        $rates = $snapshot->rates ?? throw InvalidGenerationSnapshotException::incompleteSuccess('rates');

        $providerCost = $this->calculateProviderCost->execute($usage, TokenRates::from($rates));

        return [$providerCost, $this->calculateUserCharge->execute($providerCost)];
    }

    /**
     * The values are filled onto the locked model so the casts (and the
     * offset-carrying date format) apply. The UPDATE is conditional on the
     * status read under the lock, so even a missed lock cannot double-apply.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ConcurrentGenerationTransitionException when the row changed under the lock
     */
    private function write(Generation $locked, array $attributes): void
    {
        $readStatus = $locked->status;

        $locked->fill($attributes);
        $locked->updateTimestamps();

        $updated = Generation::query()
            ->whereKey($locked->getKey())
            ->where('status', $readStatus->value)
            ->toBase()
            ->update($locked->getDirty());

        if ($updated !== 1) {
            throw ConcurrentGenerationTransitionException::rowChanged($locked->id, $readStatus, $locked->status);
        }

        $locked->syncOriginal();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotAttributes(GenerationSnapshot $snapshot): array
    {
        $attributes = [
            'status' => $snapshot->status,
            'provider_id' => $snapshot->id,
            'provider_created_at' => $snapshot->createdAt,
            'started_at' => $snapshot->startedAt,
            'completed_at' => $snapshot->completedAt,
        ];

        return match ($snapshot->status) {
            GenerationStatus::Succeeded => [
                ...$attributes,
                'input_text_tokens' => $snapshot->usage?->inputTextTokens,
                'input_image_tokens' => $snapshot->usage?->inputImageTokens,
                'output_tokens' => $snapshot->usage?->outputTokens,
                'reasoning_tokens' => $snapshot->usage?->reasoningTokens,
                'rates' => $snapshot->rates,
                'image_url' => $snapshot->imageUrl,
                'image_url_expires_at' => $snapshot->imageUrlExpiresAt,
            ],
            GenerationStatus::Failed => [
                ...$attributes,
                'error_code' => $snapshot->errorCode,
                'error_message' => $snapshot->errorMessage,
            ],
            default => $attributes,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function localFailureAttributes(LocalGenerationFailureData $failure): array
    {
        return [
            'status' => GenerationStatus::Failed,
            'local_failure_reason' => $failure->reason,
            'error_code' => $failure->errorCode,
            'error_message' => $failure->errorMessage,
            'error_param' => $failure->errorParam,
        ];
    }
}
