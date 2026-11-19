<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Enums\TokenType;
use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use Brick\Math\BigDecimal;

/**
 * A generation's own per-token prices, converted exactly from Pixelforge's
 * "USD per 1M tokens" rate strings.
 */
final readonly class TokenRates
{
    /**
     * USD per 1M tokens × 10⁹ n$/USD ÷ 10⁶ tokens = × 10³ n$ per token.
     */
    private const int RATE_TO_NANODOLLARS_PER_TOKEN_SHIFT = 3;

    /**
     * Digits with an optional fraction, as Pixelforge sends them; `\z` rejects a trailing newline.
     */
    private const string RATE_PATTERN = '/^[0-9]+(?:\.[0-9]+)?\z/';

    private function __construct(
        public Nanodollars $inputTextToken,
        public Nanodollars $inputImageToken,
        public Nanodollars $outputToken,
        public Nanodollars $reasoningToken,
    ) {}

    /**
     * Builds the rates from a generation's `rates` object.
     *
     * @param  array<array-key, mixed>  $rates
     *
     * @throws InvalidRateException
     */
    public static function from(array $rates): self
    {
        foreach (array_keys($rates) as $key) {
            if (! in_array($key, TokenType::keys(), true)) {
                throw InvalidRateException::unexpectedKey((string) $key);
            }
        }

        $perToken = [];

        foreach (TokenType::cases() as $type) {
            if (! array_key_exists($type->value, $rates)) {
                throw InvalidRateException::missingKey($type->value);
            }

            $perToken[$type->value] = self::toNanodollarsPerToken($type->value, $rates[$type->value]);
        }

        return new self(
            $perToken[TokenType::InputText->value],
            $perToken[TokenType::InputImage->value],
            $perToken[TokenType::Output->value],
            $perToken[TokenType::Reasoning->value],
        );
    }

    public function forType(TokenType $type): Nanodollars
    {
        return match ($type) {
            TokenType::InputText => $this->inputTextToken,
            TokenType::InputImage => $this->inputImageToken,
            TokenType::Output => $this->outputToken,
            TokenType::Reasoning => $this->reasoningToken,
        };
    }

    /**
     * @throws InvalidRateException
     */
    private static function toNanodollarsPerToken(string $key, mixed $rate): Nanodollars
    {
        if (! is_string($rate)) {
            throw InvalidRateException::notAString($key, get_debug_type($rate));
        }

        if (preg_match(self::RATE_PATTERN, $rate) !== 1) {
            throw InvalidRateException::malformed($key, $rate);
        }

        $perToken = BigDecimal::of($rate)->withPointMovedRight(self::RATE_TO_NANODOLLARS_PER_TOKEN_SHIFT);

        if (! $perToken->getFractionalPart()->isZero()) {
            throw InvalidRateException::notWholeNanodollars($key, $rate);
        }

        try {
            return Nanodollars::fromBigInteger($perToken->toBigInteger());
        } catch (NanodollarOverflowException $exception) {
            throw InvalidRateException::outOfRange($key, $rate, $exception);
        }
    }
}
