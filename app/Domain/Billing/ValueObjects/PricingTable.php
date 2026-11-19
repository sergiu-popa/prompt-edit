<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Exceptions\InvalidPricingException;
use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;

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

    private const string OUTPUT = 'output';

    private const string REASONING = 'reasoning';

    private const string MIN = 'min';

    private const string MAX = 'max';

    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality → size → count
     * @param  array<string, TokenBounds>  $reasoningTokens  quality → range
     */
    private function __construct(
        public TokenRates $rates,
        public int $charactersPerTextToken,
        public int $textTokenVariancePercent,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
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
            self::integerAt($tokens, self::CHARACTERS_PER_TEXT_TOKEN, self::TOKENS, min: 1),
            self::integerAt($tokens, self::TEXT_TOKEN_VARIANCE_PERCENT, self::TOKENS, min: 0, max: 100),
            self::integerAt($tokens, self::PER_REFERENCE_IMAGE, self::TOKENS, min: 0),
            self::outputTable($tokens),
            self::reasoningTable($tokens),
        );
    }

    public function outputTokens(ImageQuality $quality, ImageSize $size): int
    {
        return $this->outputTokens[$quality->value][$size->value];
    }

    public function reasoningTokens(ImageQuality $quality): TokenBounds
    {
        return $this->reasoningTokens[$quality->value];
    }

    /**
     * @param  array<array-key, mixed>  $tokens
     * @return array<string, array<string, int>>
     *
     * @throws InvalidPricingException
     */
    private static function outputTable(array $tokens): array
    {
        $path = self::TOKENS . '.' . self::OUTPUT;
        $output = self::arrayAt($tokens, self::OUTPUT, $path);
        $table = [];

        foreach (ImageQuality::cases() as $quality) {
            $qualityPath = $path . '.' . $quality->value;
            $sizes = self::arrayAt($output, $quality->value, $qualityPath);

            foreach (ImageSize::cases() as $size) {
                $table[$quality->value][$size->value] = self::integerAt($sizes, $size->value, $qualityPath, min: 0);
            }
        }

        return $table;
    }

    /**
     * @param  array<array-key, mixed>  $tokens
     * @return array<string, TokenBounds>
     *
     * @throws InvalidPricingException
     */
    private static function reasoningTable(array $tokens): array
    {
        $path = self::TOKENS . '.' . self::REASONING;
        $reasoning = self::arrayAt($tokens, self::REASONING, $path);
        $table = [];

        foreach (ImageQuality::cases() as $quality) {
            $qualityPath = $path . '.' . $quality->value;
            $range = self::arrayAt($reasoning, $quality->value, $qualityPath);
            $min = self::integerAt($range, self::MIN, $qualityPath, min: 0);
            $max = self::integerAt($range, self::MAX, $qualityPath, min: 0);

            if ($min > $max) {
                throw InvalidPricingException::outOfRange($qualityPath . '.' . self::MIN, $min, sprintf('at most its max of %d', $max));
            }

            $table[$quality->value] = TokenBounds::between($min, $max);
        }

        return $table;
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
    private static function integerAt(array $block, string $key, string $parentPath, int $min, ?int $max = null): int
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
