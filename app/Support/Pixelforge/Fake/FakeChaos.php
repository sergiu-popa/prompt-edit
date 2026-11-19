<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Fake;

use Illuminate\Container\Attributes\Config;

/**
 * The fake's unprompted chaos, off by default. Every roll is made at create:
 * the 503 on every attempt, because a refused attempt stores nothing; the
 * failure and the duplicates once, stored on the generation.
 */
final readonly class FakeChaos
{
    private const int ALWAYS = 100;

    public function __construct(
        #[Config('pixelforge.fake.chaos.overloaded_percent')]
        private int $overloadedPercent,
        #[Config('pixelforge.fake.chaos.internal_error_percent')]
        private int $internalErrorPercent,
        #[Config('pixelforge.fake.chaos.duplicate_percent')]
        private int $duplicatePercent,
    ) {}

    public function overloadsCreate(): bool
    {
        return $this->strikes($this->overloadedPercent);
    }

    public function failsGeneration(): bool
    {
        return $this->strikes($this->internalErrorPercent);
    }

    public function duplicatesWebhooks(): bool
    {
        return $this->strikes($this->duplicatePercent);
    }

    private function strikes(int $percent): bool
    {
        if ($percent <= 0) {
            return false;
        }

        if ($percent >= self::ALWAYS) {
            return true;
        }

        return random_int(1, self::ALWAYS) <= $percent;
    }
}
