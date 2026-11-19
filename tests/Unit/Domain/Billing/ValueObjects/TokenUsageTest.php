<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\ValueObjects;

use App\Domain\Billing\Exceptions\InvalidTokenUsageException;
use App\Domain\Billing\ValueObjects\TokenUsage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

final class TokenUsageTest extends TestCase
{
    private const array DOCS_SUCCEEDED_USAGE = [
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6_450,
        'reasoning_tokens' => 3_184,
        'total_tokens' => 10_411,
    ];

    public function test_builds_from_the_docs_succeeded_usage(): void
    {
        $usage = TokenUsage::from(self::DOCS_SUCCEEDED_USAGE);

        $this->assertSame(12, $usage->inputTextTokens);
        $this->assertSame(765, $usage->inputImageTokens);
        $this->assertSame(6_450, $usage->outputTokens);
        $this->assertSame(3_184, $usage->reasoningTokens);
    }

    public function test_total_tokens_is_optional(): void
    {
        $usage = self::DOCS_SUCCEEDED_USAGE;
        unset($usage['total_tokens']);

        $this->assertSame(6_450, TokenUsage::from($usage)->outputTokens);
    }

    public function test_the_constructor_accepts_zero_usage(): void
    {
        $usage = new TokenUsage(0, 0, 0, 0);

        $this->assertSame(0, $usage->inputTextTokens + $usage->inputImageTokens + $usage->outputTokens + $usage->reasoningTokens);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidUsage(): array
    {
        $usage = self::DOCS_SUCCEEDED_USAGE;

        return [
            'total off by one' => [[...$usage, 'total_tokens' => 10_412], 'total_tokens'],
            'total as a string' => [[...$usage, 'total_tokens' => '10411'], 'total_tokens'],
            'total of huge counts' => [[
                'input_text_tokens' => PHP_INT_MAX,
                'input_image_tokens' => PHP_INT_MAX,
                'output_tokens' => PHP_INT_MAX,
                'reasoning_tokens' => PHP_INT_MAX,
                'total_tokens' => PHP_INT_MAX,
            ], 'total_tokens'],
            'missing input_text_tokens' => [array_diff_key($usage, ['input_text_tokens' => true]), 'input_text_tokens'],
            'missing input_image_tokens' => [array_diff_key($usage, ['input_image_tokens' => true]), 'input_image_tokens'],
            'missing output_tokens' => [array_diff_key($usage, ['output_tokens' => true]), 'output_tokens'],
            'missing reasoning_tokens' => [array_diff_key($usage, ['reasoning_tokens' => true]), 'reasoning_tokens'],
            'unpriced token type' => [[...$usage, 'cached_tokens' => 0], 'cached_tokens'],
            'negative count' => [[...$usage, 'total_tokens' => 10_410, 'input_text_tokens' => -1], '-1'],
            'float count' => [[...$usage, 'output_tokens' => 12.0], '12.0'],
            'string count' => [[...$usage, 'output_tokens' => '12'], "'12'"],
            'null count' => [[...$usage, 'output_tokens' => null], 'output_tokens'],
        ];
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    #[DataProvider('invalidUsage')]
    public function test_rejects_usage_it_cannot_price_exactly(array $usage, string $expectedInMessage): void
    {
        $this->expectException(InvalidTokenUsageException::class);
        $this->expectExceptionMessage($expectedInMessage);

        TokenUsage::from($usage);
    }

    /**
     * @return array<string, array{array{int, int, int, int}, string}>
     */
    public static function negativeCounts(): array
    {
        return [
            'input text' => [[-1, 0, 0, 0], 'input_text_tokens'],
            'input image' => [[0, -1, 0, 0], 'input_image_tokens'],
            'output' => [[0, 0, -1, 0], 'output_tokens'],
            'reasoning' => [[0, 0, 0, PHP_INT_MIN], 'reasoning_tokens'],
        ];
    }

    /**
     * @param  array{int, int, int, int}  $counts
     */
    #[DataProvider('negativeCounts')]
    public function test_the_constructor_rejects_negative_counts(array $counts, string $expectedKey): void
    {
        $this->expectException(InvalidTokenUsageException::class);
        $this->expectExceptionMessage($expectedKey);

        new TokenUsage(...$counts);
    }

    public function test_the_constructor_rejects_floats_with_a_type_error(): void
    {
        $this->expectException(TypeError::class);

        $this->construct(0.1);
    }

    private function construct(mixed $inputTextTokens): TokenUsage
    {
        return new TokenUsage($inputTextTokens, 0, 0, 0);
    }
}
