<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DataTransferObjects;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;

/**
 * One day of the reconciliation, or its total (D-72): our recorded figures beside
 * Pixelforge's, when Pixelforge answered.
 */
final readonly class UsageComparisonLine
{
    public function __construct(
        public UsageFigures $recorded,
        public ?UsageFigures $pixelforge,
    ) {}

    /**
     * Exact equality of the five figures; null without Pixelforge's.
     */
    public function matches(): ?bool
    {
        return $this->pixelforge?->matches($this->recorded);
    }

    /**
     * The delta is Pixelforge − ours, per figure.
     *
     * @return array{recorded: array<string, int>, pixelforge: ?array<string, int>, delta: ?array<string, int>, matches: ?bool}
     *
     * @throws NanodollarOverflowException
     */
    public function toArray(): array
    {
        return [
            'recorded' => $this->recorded->toArray(),
            'pixelforge' => $this->pixelforge?->toArray(),
            'delta' => $this->pixelforge?->minus($this->recorded),
            'matches' => $this->matches(),
        ];
    }
}
