<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Billing\ValueObjects\TokenRates;
use App\Domain\Billing\ValueObjects\TokenUsage;

/**
 * Pixelforge's cost of a generation: Σ tokens × that generation's own rates.
 */
final class CalculateProviderCostAction
{
    /**
     * @throws NanodollarOverflowException
     */
    public function execute(TokenUsage $usage, TokenRates $rates): Nanodollars
    {
        return $rates->inputTextToken->multipliedBy($usage->inputTextTokens)
            ->plus($rates->inputImageToken->multipliedBy($usage->inputImageTokens))
            ->plus($rates->outputToken->multipliedBy($usage->outputTokens))
            ->plus($rates->reasoningToken->multipliedBy($usage->reasoningTokens));
    }
}
