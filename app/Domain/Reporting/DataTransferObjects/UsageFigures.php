<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DataTransferObjects;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Support\Pixelforge\DataTransferObjects\UsageRowData;

/**
 * One side of a reconciliation line (D-72): what Pixelforge billed, or what we recorded, for
 * a UTC day or in total. The generation count is carried and shown but never compared,
 * because the docs don't say whether Pixelforge's counts failed generations (§6).
 */
final readonly class UsageFigures
{
    public function __construct(
        public int $generations,
        public Nanodollars $providerCost,
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {}

    public static function zero(): self
    {
        return new self(0, Nanodollars::zero(), 0, 0, 0, 0);
    }

    public static function fromPixelforge(UsageRowData $row): self
    {
        return new self(
            $row->generations,
            $row->cost,
            $row->tokens->inputTextTokens,
            $row->tokens->inputImageTokens,
            $row->tokens->outputTokens,
            $row->tokens->reasoningTokens,
        );
    }

    /**
     * @throws NanodollarOverflowException
     */
    public function plus(self $other): self
    {
        return new self(
            $this->generations + $other->generations,
            $this->providerCost->plus($other->providerCost),
            $this->inputTextTokens + $other->inputTextTokens,
            $this->inputImageTokens + $other->inputImageTokens,
            $this->outputTokens + $other->outputTokens,
            $this->reasoningTokens + $other->reasoningTokens,
        );
    }

    /**
     * The provider cost and the four token counts are exactly equal, with no tolerance
     * (D-03); the counts are ignored.
     */
    public function matches(self $other): bool
    {
        return $this->providerCost->equals($other->providerCost)
            && $this->inputTextTokens === $other->inputTextTokens
            && $this->inputImageTokens === $other->inputImageTokens
            && $this->outputTokens === $other->outputTokens
            && $this->reasoningTokens === $other->reasoningTokens;
    }

    /**
     * The five signed deltas, this − the other.
     *
     * @return array{provider_cost_nanodollars: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}
     *
     * @throws NanodollarOverflowException
     */
    public function minus(self $other): array
    {
        return [
            'provider_cost_nanodollars' => $this->providerCost->minus($other->providerCost)->amount,
            'input_text_tokens' => $this->inputTextTokens - $other->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens - $other->inputImageTokens,
            'output_tokens' => $this->outputTokens - $other->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens - $other->reasoningTokens,
        ];
    }

    /**
     * Every amount is integer n$ (D-03).
     *
     * @return array{generations: int, provider_cost_nanodollars: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'generations' => $this->generations,
            'provider_cost_nanodollars' => $this->providerCost->amount,
            'input_text_tokens' => $this->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
