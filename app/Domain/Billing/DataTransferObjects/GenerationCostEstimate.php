<?php

declare(strict_types=1);

namespace App\Domain\Billing\DataTransferObjects;

use App\Domain\Billing\ValueObjects\CostBounds;

/**
 * What a generation will cost before it is submitted: the provider cost and the
 * user price as min, typical and max, with a line per token type.
 */
final readonly class GenerationCostEstimate
{
    /**
     * @param  array<string, EstimateLine>  $lines  keyed by token type, in TokenType::cases() order
     */
    public function __construct(
        public int $promptCharacters,
        public array $lines,
        public CostBounds $providerCost,
        public CostBounds $userPrice,
    ) {}
}
