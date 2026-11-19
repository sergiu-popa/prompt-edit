<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Enums\TokenType;
use App\Domain\Billing\Exceptions\InvalidTokenUsageException;
use Brick\Math\BigInteger;

/**
 * The token counts of a generation, one per priced token type.
 */
final readonly class TokenUsage
{
    /**
     * Pixelforge's redundant total; checked against the counts, never used to price.
     */
    private const string TOTAL_KEY = 'total_tokens';

    /**
     * @throws InvalidTokenUsageException
     */
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {
        foreach ($this->countsByKey() as $key => $count) {
            if ($count < 0) {
                throw InvalidTokenUsageException::negativeCount($key, $count);
            }
        }
    }

    /**
     * Builds the usage from a generation's `usage` object.
     *
     * @param  array<array-key, mixed>  $usage
     *
     * @throws InvalidTokenUsageException
     */
    public static function from(array $usage): self
    {
        foreach (array_keys($usage) as $key) {
            if (! in_array($key, [...TokenType::keys(), self::TOTAL_KEY], true)) {
                throw InvalidTokenUsageException::unexpectedKey((string) $key);
            }
        }

        $counts = [];

        foreach (TokenType::cases() as $type) {
            if (! array_key_exists($type->value, $usage)) {
                throw InvalidTokenUsageException::missingKey($type->value);
            }

            $counts[$type->value] = self::integerCount($type->value, $usage[$type->value]);
        }

        $tokenUsage = new self(
            $counts[TokenType::InputText->value],
            $counts[TokenType::InputImage->value],
            $counts[TokenType::Output->value],
            $counts[TokenType::Reasoning->value],
        );

        if (array_key_exists(self::TOTAL_KEY, $usage)) {
            $total = self::integerCount(self::TOTAL_KEY, $usage[self::TOTAL_KEY]);
            $sum = BigInteger::sum(...array_values($counts));

            if (! $sum->isEqualTo($total)) {
                throw InvalidTokenUsageException::totalMismatch($total, $sum->toString());
            }
        }

        return $tokenUsage;
    }

    /**
     * @return array<string, int>
     */
    private function countsByKey(): array
    {
        return [
            TokenType::InputText->value => $this->inputTextTokens,
            TokenType::InputImage->value => $this->inputImageTokens,
            TokenType::Output->value => $this->outputTokens,
            TokenType::Reasoning->value => $this->reasoningTokens,
        ];
    }

    /**
     * @throws InvalidTokenUsageException
     */
    private static function integerCount(string $key, mixed $count): int
    {
        if (! is_int($count)) {
            throw InvalidTokenUsageException::notAnInteger(
                $key,
                is_scalar($count) ? var_export($count, true) : get_debug_type($count),
            );
        }

        return $count;
    }
}
