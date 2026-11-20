<?php

declare(strict_types=1);

namespace App\Domain\Generation\DataTransferObjects;

use App\Domain\Generation\Enums\LocalFailureReason;

/**
 * What the submit job does after an attempt that Pixelforge did not accept:
 * try again after a wait, fail the generation free, or stop at the window's end.
 */
final readonly class SubmitDecision
{
    private function __construct(
        public ?int $retryInSeconds,
        public ?LocalFailureReason $failureReason,
    ) {}

    public static function retryIn(int $seconds): self
    {
        return new self($seconds, null);
    }

    /**
     * Pixelforge definitely created nothing, so the generation fails at no charge.
     */
    public static function failFree(LocalFailureReason $reason): self
    {
        return new self(null, $reason);
    }

    /**
     * The window has ended: StopSubmittingGenerationAction fails it free by its last
     * outcome, or hands it to PE-014 when it may exist at Pixelforge.
     */
    public static function stop(): self
    {
        return new self(null, null);
    }

    public function isStop(): bool
    {
        return $this->retryInSeconds === null && $this->failureReason === null;
    }
}
