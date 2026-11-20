<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\DataTransferObjects;

use App\Domain\Billing\Enums\TokenType;
use App\Domain\Billing\Exceptions\InvalidTokenUsageException;
use App\Domain\Billing\Exceptions\InvalidUsdAmountException;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Billing\ValueObjects\TokenUsage;
use App\Support\Pixelforge\Exceptions\InvalidUsageReportException;

/**
 * One day of Pixelforge's usage report, or its total: what Pixelforge billed this key,
 * in exact integers (D-73).
 */
final readonly class UsageRowData
{
    public function __construct(
        public int $generations,
        public TokenUsage $tokens,
        public Nanodollars $cost,
    ) {}

    /**
     * Every count is a non-negative int and `cost_usd` an unsigned 9-decimal string, read by
     * PE-001's parser, never a float. Unknown keys are ignored.
     *
     * @param  array<array-key, mixed>  $row
     * @param  string  $path  where the row is, for the error: `data[0]` or `total`
     *
     * @throws InvalidUsageReportException
     */
    public static function from(array $row, string $path): self
    {
        $counts = [];

        foreach (['generations', ...TokenType::keys()] as $field) {
            $counts[$field] = self::count($row, $path, $field);
        }

        try {
            $tokens = new TokenUsage(
                $counts[TokenType::InputText->value],
                $counts[TokenType::InputImage->value],
                $counts[TokenType::Output->value],
                $counts[TokenType::Reasoning->value],
            );
        } catch (InvalidTokenUsageException $exception) {
            throw InvalidUsageReportException::invalidField($path, 'non-negative token counts', null, $exception);
        }

        return new self($counts['generations'], $tokens, self::cost($row, $path));
    }

    /**
     * The docs' fields in the docs' order, without the date.
     *
     * @return array{generations: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, cost_usd: string}
     */
    public function toArray(): array
    {
        return [
            'generations' => $this->generations,
            'input_text_tokens' => $this->tokens->inputTextTokens,
            'input_image_tokens' => $this->tokens->inputImageTokens,
            'output_tokens' => $this->tokens->outputTokens,
            'reasoning_tokens' => $this->tokens->reasoningTokens,
            'cost_usd' => $this->cost->toUsdString(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $row
     *
     * @throws InvalidUsageReportException
     */
    private static function count(array $row, string $path, string $field): int
    {
        if (! array_key_exists($field, $row)) {
            throw InvalidUsageReportException::missingField("{$path}.{$field}");
        }

        $count = $row[$field];

        if (! is_int($count) || $count < 0) {
            throw InvalidUsageReportException::invalidField("{$path}.{$field}", 'a non-negative integer', $count);
        }

        return $count;
    }

    /**
     * The parser accepts a leading minus, which a cost never has, `-0.000000000` included.
     *
     * @param  array<array-key, mixed>  $row
     *
     * @throws InvalidUsageReportException
     */
    private static function cost(array $row, string $path): Nanodollars
    {
        if (! array_key_exists('cost_usd', $row)) {
            throw InvalidUsageReportException::missingField("{$path}.cost_usd");
        }

        $cost = $row['cost_usd'];
        $expected = 'an unsigned decimal string with at most 9 decimals';

        if (! is_string($cost) || str_starts_with($cost, '-')) {
            throw InvalidUsageReportException::invalidField("{$path}.cost_usd", $expected, $cost);
        }

        try {
            return Nanodollars::fromUsdString($cost);
        } catch (InvalidUsdAmountException $exception) {
            throw InvalidUsageReportException::invalidField("{$path}.cost_usd", $expected, $cost, $exception);
        }
    }
}
