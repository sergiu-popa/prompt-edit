<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Billing\ValueObjects\CreditBalance;
use App\Domain\Billing\ValueObjects\Nanodollars;
use RuntimeException;

/**
 * A new generation's hold, its max user price, is more than the balance has available
 * (D-68), so nothing was written. It carries the amounts in integer n$ for the
 * Generate form, which formats them; the message is for logs only.
 */
final class InsufficientCreditsException extends RuntimeException
{
    private function __construct(
        private readonly Nanodollars $required,
        private readonly Nanodollars $available,
        private readonly Nanodollars $shortfall,
    ) {
        parent::__construct(sprintf(
            'The hold of %d n$ exceeds the %d n$ available by %d n$.',
            $required->amount,
            $available->amount,
            $shortfall->amount,
        ));
    }

    /**
     * @throws NanodollarOverflowException
     */
    public static function forHold(Nanodollars $required, CreditBalance $balance): self
    {
        return new self($required, $balance->available, $balance->shortfallFor($required));
    }

    public function required(): Nanodollars
    {
        return $this->required;
    }

    public function available(): Nanodollars
    {
        return $this->available;
    }

    public function shortfall(): Nanodollars
    {
        return $this->shortfall;
    }

    /**
     * The `credit_shortfall` flash (resources/js/types/credits.ts).
     *
     * @return array{required_nanodollars: int, available_nanodollars: int, shortfall_nanodollars: int}
     */
    public function toArray(): array
    {
        return [
            'required_nanodollars' => $this->required->amount,
            'available_nanodollars' => $this->available->amount,
            'shortfall_nanodollars' => $this->shortfall->amount,
        ];
    }
}
