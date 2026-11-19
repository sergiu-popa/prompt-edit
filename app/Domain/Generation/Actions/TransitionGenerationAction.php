<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use Illuminate\Support\Facades\DB;

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

            $outcome = $this->classify($locked->status, $change->status);

            if ($outcome === GenerationTransitionOutcome::Applied) {
                $this->apply($locked, $change);
            }

            return $outcome;
        });
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
