<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;

/**
 * A user's credit as the ledger derives it (D-67): what they may still hold, what
 * the holds of their running generations take, and how many those are. Available
 * can be negative, after a charge above its hold (requirements §6).
 */
final readonly class CreditBalance
{
    public function __construct(
        public Nanodollars $available,
        public Nanodollars $reserved,
        public int $runningGenerations,
    ) {}

    /**
     * Zero when the balance covers the amount, else exactly what is missing.
     *
     * @throws NanodollarOverflowException
     */
    public function shortfallFor(Nanodollars $required): Nanodollars
    {
        if ($this->available->compareTo($required) >= 0) {
            return Nanodollars::zero();
        }

        return $required->minus($this->available);
    }

    /**
     * The shared `credits` prop (resources/js/types/credits.ts), in integer n$.
     *
     * @return array{available_nanodollars: int, reserved_nanodollars: int, running_generations: int}
     */
    public function toArray(): array
    {
        return [
            'available_nanodollars' => $this->available->amount,
            'reserved_nanodollars' => $this->reserved->amount,
            'running_generations' => $this->runningGenerations,
        ];
    }
}
