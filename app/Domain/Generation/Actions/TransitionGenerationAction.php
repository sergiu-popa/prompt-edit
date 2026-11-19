<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single path that changes a generation's status (D-06), for create and
 * replay responses, webhooks and the poller. Status only moves to a higher
 * rank, terminal statuses never change, and only an `Applied` outcome writes.
 */
final class TransitionGenerationAction
{
    /**
     * The given model is used only for its key and is never mutated; callers
     * refresh it.
     */
    public function execute(Generation $generation, GenerationSnapshot $change): GenerationTransitionOutcome
    {
        return DB::transaction(function () use ($generation, $change): GenerationTransitionOutcome {
            $locked = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            $identityConflict = $this->identityConflict($locked, $change);

            if ($identityConflict !== null) {
                $this->logConflict($identityConflict, $locked, $change, GenerationTransitionOutcome::RefusedConflict);

                return GenerationTransitionOutcome::RefusedConflict;
            }

            $outcome = $this->classify($locked->status, $change->status);

            if ($outcome === GenerationTransitionOutcome::IgnoredConflict) {
                $this->logConflict(
                    'Ignored a Pixelforge snapshot whose terminal status contradicts the generation\'s.',
                    $locked,
                    $change,
                    $outcome,
                );
            }

            if ($outcome === GenerationTransitionOutcome::Applied) {
                $this->apply($locked, $change);
            }

            return $outcome;
        });
    }

    /**
     * Checked before any rank, so a snapshot of another generation never
     * touches this one, whatever its status.
     */
    private function identityConflict(Generation $locked, GenerationSnapshot $snapshot): ?string
    {
        if ($snapshot->generationId === null) {
            return 'Refused a Pixelforge snapshot without our generation id.';
        }

        if ($snapshot->generationId !== $locked->id) {
            return 'Refused a Pixelforge snapshot of another generation.';
        }

        if ($locked->provider_id !== null && $snapshot->id !== $locked->provider_id) {
            return 'Refused a Pixelforge snapshot whose id differs from the stored provider id.';
        }

        return null;
    }

    private function logConflict(
        string $message,
        Generation $locked,
        GenerationSnapshot $snapshot,
        GenerationTransitionOutcome $outcome,
    ): void {
        Log::error($message, [
            'generation_id' => $locked->id,
            'status' => $locked->status->value,
            'provider_id' => $locked->provider_id,
            'incoming_status' => $snapshot->status->value,
            'incoming_provider_id' => $snapshot->id,
            'outcome' => $outcome->value,
        ]);
    }

    private function classify(GenerationStatus $current, GenerationStatus $incoming): GenerationTransitionOutcome
    {
        if ($current->isTerminal()) {
            return match (true) {
                $incoming === $current => GenerationTransitionOutcome::IgnoredDuplicate,
                $incoming->isTerminal() => GenerationTransitionOutcome::IgnoredConflict,
                default => GenerationTransitionOutcome::IgnoredTerminal,
            };
        }

        return match ($incoming->rank() <=> $current->rank()) {
            1 => GenerationTransitionOutcome::Applied,
            0 => GenerationTransitionOutcome::IgnoredDuplicate,
            -1 => GenerationTransitionOutcome::IgnoredStale,
        };
    }

    private function apply(Generation $locked, GenerationSnapshot $snapshot): void
    {
        $locked->fill([
            'status' => $snapshot->status,
            'provider_id' => $snapshot->id,
            'provider_created_at' => $snapshot->createdAt,
            'started_at' => $snapshot->startedAt,
            'completed_at' => $snapshot->completedAt,
        ]);

        if ($snapshot->status === GenerationStatus::Succeeded) {
            $locked->fill([
                'input_text_tokens' => $snapshot->usage?->inputTextTokens,
                'input_image_tokens' => $snapshot->usage?->inputImageTokens,
                'output_tokens' => $snapshot->usage?->outputTokens,
                'reasoning_tokens' => $snapshot->usage?->reasoningTokens,
                'rates' => $snapshot->rates,
                'image_url' => $snapshot->imageUrl,
                'image_url_expires_at' => $snapshot->imageUrlExpiresAt,
            ]);
        }

        if ($snapshot->status === GenerationStatus::Failed) {
            $locked->fill([
                'error_code' => $snapshot->errorCode,
                'error_message' => $snapshot->errorMessage,
            ]);
        }

        $locked->save();
    }
}
