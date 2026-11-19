<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

/**
 * The min, typical and max of an estimated amount.
 */
final readonly class CostBounds
{
    public function __construct(
        public Nanodollars $min,
        public Nanodollars $typical,
        public Nanodollars $max,
    ) {}
}
