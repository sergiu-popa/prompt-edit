<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\ValueObjects;

use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\ValueObjects\TokenRates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenRatesTest extends TestCase
{
    private const array PUBLISHED_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    public function test_published_rates_convert_to_whole_nanodollars_per_token(): void
    {
        $rates = TokenRates::from(self::PUBLISHED_RATES);

        $this->assertSame(5_000, $rates->inputTextToken->amount);
        $this->assertSame(10_000, $rates->inputImageToken->amount);
        $this->assertSame(40_000, $rates->outputToken->amount);
        $this->assertSame(12_500, $rates->reasoningToken->amount);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function convertibleRates(): array
    {
        return [
            '5.00' => ['5.00', 5_000],
            '10.00' => ['10.00', 10_000],
            '40.00' => ['40.00', 40_000],
            '12.50' => ['12.50', 12_500],
            '12.5' => ['12.5', 12_500],
            '12.500000' => ['12.500000', 12_500],
            'whole number' => ['5', 5_000],
            '0.001' => ['0.001', 1],
            'zero' => ['0', 0],
            '0.00' => ['0.00', 0],
            'PHP_INT_MAX n$ per token' => ['9223372036854775.807', PHP_INT_MAX],
        ];
    }

    #[DataProvider('convertibleRates')]
    public function test_rate_strings_convert_exactly(string $rate, int $expectedNanodollarsPerToken): void
    {
        $rates = TokenRates::from([...self::PUBLISHED_RATES, 'output_tokens' => $rate]);

        $this->assertSame($expectedNanodollarsPerToken, $rates->outputToken->amount);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidRateValues(): array
    {
        return [
            'half a nanodollar' => ['0.0005'],
            'sub-nanodollar remainder' => ['12.5005'],
            'empty' => [''],
            'letters' => ['abc'],
            'decimal comma' => ['5,00'],
            'leading space' => [' 5.00'],
            'trailing space' => ['5.00 '],
            'trailing newline' => ["5.00\n"],
            'negative' => ['-5.00'],
            'leading plus' => ['+5.00'],
            'exponent' => ['1e3'],
            'no fraction digits' => ['5.'],
            'no integer part' => ['.5'],
            'non-ASCII digit' => ["\u{0665}.00"],
            'above PHP_INT_MAX n$ per token' => ['9223372036854775.808'],
            'JSON number' => [12.5],
            'integer' => [5],
            'null' => [null],
            'array' => [['5.00']],
        ];
    }

    #[DataProvider('invalidRateValues')]
    public function test_rejects_rates_that_are_not_whole_nanodollars_or_not_plain_decimal_strings(mixed $rate): void
    {
        $this->expectException(InvalidRateException::class);
        $this->expectExceptionMessage('output_tokens');

        TokenRates::from([...self::PUBLISHED_RATES, 'output_tokens' => $rate]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonWholeRates(): array
    {
        return [
            '0.0005' => ['0.0005'],
            '12.5005' => ['12.5005'],
        ];
    }

    #[DataProvider('nonWholeRates')]
    public function test_a_non_whole_rate_is_named_in_the_message_and_never_rounded(string $rate): void
    {
        $this->expectException(InvalidRateException::class);
        $this->expectExceptionMessage('"' . $rate . '"');

        TokenRates::from([...self::PUBLISHED_RATES, 'reasoning_tokens' => $rate]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredKeys(): array
    {
        return [
            'input_text_tokens' => ['input_text_tokens'],
            'input_image_tokens' => ['input_image_tokens'],
            'output_tokens' => ['output_tokens'],
            'reasoning_tokens' => ['reasoning_tokens'],
        ];
    }

    #[DataProvider('requiredKeys')]
    public function test_rejects_rates_missing_a_token_type(string $missingKey): void
    {
        $rates = self::PUBLISHED_RATES;
        unset($rates[$missingKey]);

        $this->expectException(InvalidRateException::class);
        $this->expectExceptionMessage($missingKey);

        TokenRates::from($rates);
    }

    public function test_rejects_a_token_type_it_cannot_price(): void
    {
        $this->expectException(InvalidRateException::class);
        $this->expectExceptionMessage('cached_tokens');

        TokenRates::from([...self::PUBLISHED_RATES, 'cached_tokens' => '1.00']);
    }
}
