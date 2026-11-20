<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DataTransferObjects;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\Nanodollars;

/**
 * One cell group of the operator report (D-64): how many generations Pixelforge billed,
 * what we charged users for them, and what Pixelforge cost us. Profit is derived, and it may
 * be negative.
 */
final readonly class ReportFigures
{
    public function __construct(
        public int $succeeded,
        public Nanodollars $spend,
        public Nanodollars $providerCost,
    ) {}

    public static function zero(): self
    {
        return new self(0, Nanodollars::zero(), Nanodollars::zero());
    }

    /**
     * @throws NanodollarOverflowException
     */
    public function plus(self $other): self
    {
        return new self(
            $this->succeeded + $other->succeeded,
            $this->spend->plus($other->spend),
            $this->providerCost->plus($other->providerCost),
        );
    }

    /**
     * Spend − provider cost.
     *
     * @throws NanodollarOverflowException
     */
    public function profit(): Nanodollars
    {
        return $this->spend->minus($this->providerCost);
    }

    /**
     * Every amount is integer n$ (D-03).
     *
     * @return array{succeeded: int, spend_nanodollars: int, provider_cost_nanodollars: int, profit_nanodollars: int}
     *
     * @throws NanodollarOverflowException
     */
    public function toArray(): array
    {
        return [
            'succeeded' => $this->succeeded,
            'spend_nanodollars' => $this->spend->amount,
            'provider_cost_nanodollars' => $this->providerCost->amount,
            'profit_nanodollars' => $this->profit()->amount,
        ];
    }
}
