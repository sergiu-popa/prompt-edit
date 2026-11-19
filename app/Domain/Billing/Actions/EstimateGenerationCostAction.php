<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\DataTransferObjects\EstimateLine;
use App\Domain\Billing\DataTransferObjects\GenerationCostEstimate;
use App\Domain\Billing\Enums\TokenType;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\CostBounds;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Billing\ValueObjects\PricingTable;
use App\Domain\Billing\ValueObjects\TokenBounds;
use App\Domain\Billing\ValueObjects\TokenUsage;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Closure;

/**
 * The authoritative upfront estimate of a generation (requirements §3). The
 * TypeScript mirror in resources/js/lib/estimate.ts is held to the same
 * shared fixture.
 *
 * Min, typical and max are each priced as a whole usage, and the markup is
 * applied once to each total, exactly as settlement will charge (D-16).
 */
final class EstimateGenerationCostAction
{
    private const int PERCENT = 100;

    public function __construct(
        private readonly PricingTable $pricing,
        private readonly CalculateProviderCostAction $calculateProviderCost,
        private readonly CalculateUserChargeAction $calculateUserCharge,
    ) {}

    /**
     * @throws NanodollarOverflowException
     */
    public function execute(string $prompt, ImageQuality $quality, ImageSize $size, int $referenceImageCount): GenerationCostEstimate
    {
        $promptCharacters = mb_strlen($prompt, 'UTF-8');

        $tokens = [
            TokenType::InputText->value => $this->textTokens($promptCharacters),
            TokenType::InputImage->value => TokenBounds::exact(
                BigInteger::of($this->pricing->tokensPerReferenceImage)->multipliedBy($referenceImageCount)->toInt(),
            ),
            TokenType::Output->value => TokenBounds::exact($this->pricing->outputTokens($quality, $size)),
            TokenType::Reasoning->value => $this->pricing->reasoningTokens($quality),
        ];

        $lines = [];

        foreach (TokenType::cases() as $type) {
            $lines[$type->value] = $this->line($type, $tokens[$type->value]);
        }

        $providerCost = new CostBounds(
            $this->providerCost($tokens, fn (TokenBounds $bounds): int => $bounds->min),
            $this->providerCost($tokens, fn (TokenBounds $bounds): int => $bounds->typical),
            $this->providerCost($tokens, fn (TokenBounds $bounds): int => $bounds->max),
        );

        return new GenerationCostEstimate(
            $promptCharacters,
            $lines,
            $providerCost,
            new CostBounds(
                $this->calculateUserCharge->execute($providerCost->min),
                $this->calculateUserCharge->execute($providerCost->typical),
                $this->calculateUserCharge->execute($providerCost->max),
            ),
        );
    }

    /**
     * e = ceil(characters ÷ characters per token); the bounds are e ∓ the
     * variance, floored and ceiled to whole tokens, so the midpoint is e.
     */
    private function textTokens(int $promptCharacters): TokenBounds
    {
        $estimated = BigInteger::of($promptCharacters)
            ->dividedBy($this->pricing->charactersPerTextToken, RoundingMode::Ceiling);
        $variance = $this->pricing->textTokenVariancePercent;

        return TokenBounds::between(
            $estimated->multipliedBy(self::PERCENT - $variance)->dividedBy(self::PERCENT, RoundingMode::Floor)->toInt(),
            $estimated->multipliedBy(self::PERCENT + $variance)->dividedBy(self::PERCENT, RoundingMode::Ceiling)->toInt(),
        );
    }

    /**
     * @throws NanodollarOverflowException
     */
    private function line(TokenType $type, TokenBounds $tokens): EstimateLine
    {
        $rate = $this->pricing->rates->forType($type);

        return new EstimateLine(
            $type,
            $type->isKnownUpfront(),
            $tokens,
            new CostBounds(
                $rate->multipliedBy($tokens->min),
                $rate->multipliedBy($tokens->typical),
                $rate->multipliedBy($tokens->max),
            ),
        );
    }

    /**
     * @param  array<string, TokenBounds>  $tokens
     * @param  Closure(TokenBounds): int  $bound
     *
     * @throws NanodollarOverflowException
     */
    private function providerCost(array $tokens, Closure $bound): Nanodollars
    {
        return $this->calculateProviderCost->execute(
            new TokenUsage(
                $bound($tokens[TokenType::InputText->value]),
                $bound($tokens[TokenType::InputImage->value]),
                $bound($tokens[TokenType::Output->value]),
                $bound($tokens[TokenType::Reasoning->value]),
            ),
            $this->pricing->rates,
        );
    }
}
