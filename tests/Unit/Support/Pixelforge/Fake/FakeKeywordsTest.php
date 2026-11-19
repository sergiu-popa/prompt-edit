<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Pixelforge\Fake;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Fake\FakeGenerationOutcome;
use App\Support\Pixelforge\Fake\FakeKeywords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keyword detection and the documented precedence between keywords.
 */
final class FakeKeywordsTest extends TestCase
{
    private const array KEYWORDS = [
        'blocked' => '[blocked]',
        'overloaded' => '[overloaded]',
        'fail' => '[fail]',
        'succeed' => '[succeed]',
        'slow' => '[slow]',
        'duplicate' => '[duplicate]',
        'expire' => '[expire]',
    ];

    #[DataProvider('placements')]
    public function test_each_keyword_is_found_anywhere_in_any_case(string $property, string $prompt): void
    {
        $keywords = FakeKeywords::fromPrompt($prompt);

        foreach (array_keys(self::KEYWORDS) as $other) {
            $this->assertSame($other === $property, $keywords->{$other}, $other);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function placements(): array
    {
        $cases = [];

        foreach (self::KEYWORDS as $property => $keyword) {
            $cases["{$property} at the start"] = [$property, "{$keyword} a lighthouse"];
            $cases["{$property} in the middle"] = [$property, "a {$keyword} lighthouse"];
            $cases["{$property} at the end"] = [$property, "a lighthouse {$keyword}"];
            $cases["{$property} in upper case"] = [$property, 'a ' . strtoupper($keyword) . ' lighthouse'];
            $cases["{$property} in mixed case"] = [$property, 'a [' . ucfirst($property) . '] lighthouse'];
        }

        return $cases;
    }

    #[DataProvider('nearMisses')]
    public function test_a_near_miss_is_no_keyword(string $prompt): void
    {
        $keywords = FakeKeywords::fromPrompt($prompt);

        foreach (array_keys(self::KEYWORDS) as $property) {
            $this->assertFalse($keywords->{$property}, $property);
        }

        $this->assertNull($keywords->rejection());
        $this->assertNull($keywords->outcome());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nearMisses(): array
    {
        return [
            'spaces inside the brackets' => ['a [ blocked ] [ fail ] lighthouse'],
            'no brackets' => ['a blocked, overloaded, fail lighthouse'],
            'a longer word' => ['a [blockedx] [failure] [slower] lighthouse'],
            'fullwidth brackets' => ['a ［blocked］ ［fail］ lighthouse'],
            'an emoji prompt' => ['🎨 a lighthouse 🎨'],
            'empty' => [''],
        ];
    }

    #[DataProvider('rejections')]
    public function test_blocked_comes_before_overloaded_before_fail(string $prompt, ?PixelforgeErrorCode $expected): void
    {
        $this->assertSame($expected, FakeKeywords::fromPrompt($prompt)->rejection());
    }

    /**
     * @return array<string, array{string, ?PixelforgeErrorCode}>
     */
    public static function rejections(): array
    {
        return [
            'blocked' => ['a [blocked] lighthouse', PixelforgeErrorCode::ContentPolicyViolation],
            'overloaded' => ['a [overloaded] lighthouse', PixelforgeErrorCode::Overloaded],
            'both, overloaded first' => ['[overloaded] a [blocked] lighthouse', PixelforgeErrorCode::ContentPolicyViolation],
            'none' => ['a lighthouse', null],
            'fail is no rejection' => ['a [fail] lighthouse', null],
            'every rejection and fail' => ['a [fail][overloaded][blocked] lighthouse', PixelforgeErrorCode::ContentPolicyViolation],
            'overloaded and fail' => ['a [fail] [overloaded] lighthouse', PixelforgeErrorCode::Overloaded],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_fail_then_succeed_decides_the_outcome(string $prompt, ?FakeGenerationOutcome $expected): void
    {
        $this->assertSame($expected, FakeKeywords::fromPrompt($prompt)->outcome());
    }

    /**
     * @return array<string, array{string, ?FakeGenerationOutcome}>
     */
    public static function outcomes(): array
    {
        return [
            'fail' => ['a [fail] lighthouse', FakeGenerationOutcome::GenerationFailed],
            'fail in upper case' => ['a [FAIL] lighthouse', FakeGenerationOutcome::GenerationFailed],
            'succeed' => ['a [succeed] lighthouse', FakeGenerationOutcome::Succeeded],
            'fail before succeed' => ['a [succeed][fail] lighthouse', FakeGenerationOutcome::GenerationFailed],
            'none' => ['a lighthouse', null],
            'a rejection alone decides no outcome' => ['a [blocked] lighthouse', null],
            'a modifier decides no outcome' => ['a [slow] [duplicate] [expire] lighthouse', null],
        ];
    }
}
