<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\ValueObjects;

use App\Domain\Billing\Exceptions\InvalidPricingException;
use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\ValueObjects\PricingTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\InteractsWithPricing;

final class PricingTableTest extends TestCase
{
    use InteractsWithPricing;

    public function test_the_pinned_snapshot_builds_rates_in_whole_nanodollars_per_token(): void
    {
        $table = PricingTable::fromSnapshot(self::pinnedPricingSnapshot());

        $this->assertSame(5_000, $table->rates->inputTextToken->amount);
        $this->assertSame(10_000, $table->rates->inputImageToken->amount);
        $this->assertSame(40_000, $table->rates->outputToken->amount);
        $this->assertSame(12_500, $table->rates->reasoningToken->amount);
    }

    public function test_the_pinned_snapshot_builds_the_text_and_reference_image_rules(): void
    {
        $table = PricingTable::fromSnapshot(self::pinnedPricingSnapshot());

        $this->assertSame(4, $table->charactersPerTextToken);
        $this->assertSame(15, $table->textTokenVariancePercent);
        $this->assertSame(765, $table->tokensPerReferenceImage);
    }

    /**
     * @return array<string, array{string, int, callable(PricingTable): int}>
     */
    public static function acceptedScalarEdges(): array
    {
        return [
            'no text variance' => ['text_token_variance_percent', 0, fn (PricingTable $table): int => $table->textTokenVariancePercent],
            'full text variance' => ['text_token_variance_percent', 100, fn (PricingTable $table): int => $table->textTokenVariancePercent],
            'one character per token' => ['characters_per_text_token', 1, fn (PricingTable $table): int => $table->charactersPerTextToken],
            'free reference images' => ['per_reference_image', 0, fn (PricingTable $table): int => $table->tokensPerReferenceImage],
        ];
    }

    /**
     * @param  callable(PricingTable): int  $read
     */
    #[DataProvider('acceptedScalarEdges')]
    public function test_accepts_scalar_rules_at_their_edges(string $key, int $value, callable $read): void
    {
        $table = PricingTable::fromSnapshot(self::snapshotWith(['tokens' => [$key => $value]]));

        $this->assertSame($value, $read($table));
    }

    public function test_a_rate_that_is_not_a_whole_nanodollar_per_token_throws_at_build_time(): void
    {
        $this->expectException(InvalidRateException::class);
        $this->expectExceptionMessage('"0.0005"');

        PricingTable::fromSnapshot(self::snapshotWith(['rates' => ['input_text_tokens' => '0.0005']]));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidScalarRules(): array
    {
        return [
            'variance as the live float' => [['text_token_variance_percent' => 0.15], 'tokens.text_token_variance_percent'],
            'variance as a string' => [['text_token_variance_percent' => '15'], 'tokens.text_token_variance_percent'],
            'negative variance' => [['text_token_variance_percent' => -1], 'tokens.text_token_variance_percent'],
            'variance above 100' => [['text_token_variance_percent' => 101], 'tokens.text_token_variance_percent'],
            'zero characters per token' => [['characters_per_text_token' => 0], 'tokens.characters_per_text_token'],
            'negative tokens per reference image' => [['per_reference_image' => -1], 'tokens.per_reference_image'],
        ];
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    #[DataProvider('invalidScalarRules')]
    public function test_rejects_scalar_rules_it_cannot_estimate_with(array $tokens, string $expectedPath): void
    {
        $this->expectException(InvalidPricingException::class);
        $this->expectExceptionMessage($expectedPath);

        PricingTable::fromSnapshot(self::snapshotWith(['tokens' => $tokens]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredPaths(): array
    {
        return [
            'rates block' => ['rates'],
            'tokens block' => ['tokens'],
            'characters per text token' => ['tokens.characters_per_text_token'],
            'text token variance' => ['tokens.text_token_variance_percent'],
            'tokens per reference image' => ['tokens.per_reference_image'],
        ];
    }

    #[DataProvider('requiredPaths')]
    public function test_rejects_a_snapshot_missing_a_required_entry(string $path): void
    {
        $this->expectException(InvalidPricingException::class);
        $this->expectExceptionMessage($path);

        PricingTable::fromSnapshot(self::snapshotWithout($path));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function snapshotWith(array $overrides): array
    {
        return array_replace_recursive(self::pinnedPricingSnapshot(), $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshotWithout(string $path): array
    {
        $snapshot = self::pinnedPricingSnapshot();
        $keys = explode('.', $path);
        $last = array_pop($keys);
        $node = &$snapshot;

        foreach ($keys as $key) {
            $node = &$node[$key];
        }

        unset($node[$last]);

        return $snapshot;
    }
}
