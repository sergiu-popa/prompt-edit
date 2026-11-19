<?php

declare(strict_types=1);

namespace App\Domain\Billing\DataTransferObjects;

use App\Domain\Billing\Enums\TokenType;
use App\Domain\Billing\ValueObjects\CostBounds;
use App\Domain\Billing\ValueObjects\TokenBounds;

/**
 * One token type's share of an estimate, at the provider's rate.
 */
final readonly class EstimateLine
{
    public function __construct(
        public TokenType $type,
        public bool $isExact,
        public TokenBounds $tokens,
        public CostBounds $providerCost,
    ) {}
}
