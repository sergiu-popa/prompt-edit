<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Exceptions\InvalidPricingException;
use App\Domain\Billing\Exceptions\InvalidRateException;

/**
 * The prices and token rules an estimate is computed from, built from a
 * `/v1/pricing`-shaped snapshot (D-15).
 *
 * Everything is validated when the table is built, so an estimate never meets
 * a missing entry or a silent zero.
 */
final readonly class PricingTable
{
    private const string RATES = 'rates';

    private const string TOKENS = 'tokens';

    private const string CHARACTERS_PER_TEXT_TOKEN = 'characters_per_text_token';

    private const string TEXT_TOKEN_VARIANCE_PERCENT = 'text_token_variance_percent';

    private const string PER_REFERENCE_IMAGE = 'per_reference_image';

    private function __construct(
        public TokenRates $rates,
        public int $charactersPerTextToken,
        public int $textTokenVariancePercent,
        public int $tokensPerReferenceImage,
    ) {}

    /**
     * @param  array<array-key, mixed>  $snapshot
     *
     * @throws InvalidPricingException
     * @throws InvalidRateException
     */
    public static function fromSnapshot(array $snapshot): self
    {
        $rates = TokenRates::from(self::arrayAt($snapshot, self::RATES, self::RATES));
        $tokens = self::arrayAt($snapshot, self::TOKENS, self::TOKENS);

        return new self(
            $rates,
            self::integerAt($tokens, self::CHARACTERS_PER_TEXT_TOKEN, min: 1),
            self::integerAt($tokens, self::TEXT_TOKEN_VARIANCE_PERCENT, min: 0, max: 100),
            self::integerAt($tokens, self::PER_REFERENCE_IMAGE, min: 0),
        );
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<array-key, mixed>
     *
     * @throws InvalidPricingException
     */
    private static function arrayAt(array $block, string $key, string $path): array
    {
        if (! array_key_exists($key, $block)) {
            throw InvalidPricingException::missing($path);
        }

        if (! is_array($block[$key])) {
            throw InvalidPricingException::notAnArray($path, get_debug_type($block[$key]));
        }

        return $block[$key];
    }

    /**
     * @param  array<array-key, mixed>  $block
     *
     * @throws InvalidPricingException
     */
    private static function integerAt(array $block, string $key, int $min, ?int $max = null, string $parentPath = self::TOKENS): int
    {
        $path = $parentPath . '.' . $key;

        if (! array_key_exists($key, $block)) {
            throw InvalidPricingException::missing($path);
        }

        $value = $block[$key];

        if (! is_int($value)) {
            throw InvalidPricingException::notAnInteger(
                $path,
                is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            );
        }

        if ($value < $min) {
            throw InvalidPricingException::outOfRange($path, $value, sprintf('at least %d', $min));
        }

        if ($max !== null && $value > $max) {
            throw InvalidPricingException::outOfRange($path, $value, sprintf('at most %d', $max));
        }

        return $value;
    }
}
