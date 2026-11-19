<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Actions;

use App\Domain\Billing\Actions\CalculateProviderCostAction;
use App\Domain\Billing\Actions\CalculateUserChargeAction;
use App\Domain\Billing\Actions\EstimateGenerationCostAction;
use App\Domain\Billing\DataTransferObjects\EstimateLine;
use App\Domain\Billing\DataTransferObjects\GenerationCostEstimate;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\CostBounds;
use App\Domain\Billing\ValueObjects\PricingTable;
use App\Domain\Billing\ValueObjects\TokenBounds;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\InteractsWithPricing;

/**
 * Runs every case of tests/fixtures/estimates.json, the contract the TypeScript
 * mirror is held to as well.
 *
 * @phpstan-type EstimateCase array{
 *     name: string,
 *     prompt: string,
 *     prompt_repeat?: int,
 *     quality: string,
 *     size: string,
 *     reference_image_count: int,
 *     pricing_overrides?: PricingOverrides,
 *     expected: array<string, mixed>,
 * }
 * @phpstan-type PricingOverrides array{
 *     nanodollars_per_token?: array<string, int>,
 *     tokens?: array<string, mixed>,
 * }
 */
final class EstimateGenerationCostActionTest extends TestCase
{
    use InteractsWithPricing;

    /**
     * n$ per token ÷ 10³ = USD per 1M tokens.
     */
    private const int RATE_STRING_SCALE = 3;

    /**
     * @return array<string, array{EstimateCase}>
     */
    public static function fixtureCases(): array
    {
        $cases = [];

        foreach (self::cases() as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    /**
     * @param  EstimateCase  $case
     */
    #[DataProvider('fixtureCases')]
    public function test_the_estimate_matches_the_shared_fixture(array $case): void
    {
        $overrides = $case['pricing_overrides'] ?? [];
        $pricing = PricingTable::fromSnapshot(self::snapshotWith($overrides));

        $this->assertSame(
            array_replace_recursive(self::fixturePricing(), $overrides),
            $pricing->toArray(),
            'The case must price the same table the TypeScript suite builds from the fixture.',
        );

        $estimate = self::action($pricing)->execute(
            str_repeat($case['prompt'], $case['prompt_repeat'] ?? 1),
            ImageQuality::from($case['quality']),
            ImageSize::from($case['size']),
            $case['reference_image_count'],
        );

        $this->assertSame($case['expected'], self::toFixtureShape($estimate));
    }

    public function test_every_fixture_case_has_a_unique_name(): void
    {
        $names = array_column(self::cases(), 'name');

        $this->assertNotEmpty($names);
        $this->assertSame($names, array_values(array_unique($names)));
    }

    public function test_a_negative_reference_count_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference image count must not be negative, got -1.');

        self::action(PricingTable::fromSnapshot(self::pinnedPricingSnapshot()))
            ->execute('a cat', ImageQuality::Low, ImageSize::Square, -1);
    }

    public function test_an_image_token_count_beyond_64_bits_surfaces_as_a_nanodollar_overflow(): void
    {
        $this->expectException(NanodollarOverflowException::class);
        $this->expectExceptionMessage(sprintf('Token count %s is outside the signed 64-bit range.', BigInteger::of(765)->multipliedBy(PHP_INT_MAX)->toString()));

        self::action(PricingTable::fromSnapshot(self::pinnedPricingSnapshot()))
            ->execute('a cat', ImageQuality::Low, ImageSize::Square, PHP_INT_MAX);
    }

    /**
     * @return list<EstimateCase>
     */
    private static function cases(): array
    {
        /** @var list<EstimateCase> $cases */
        $cases = self::estimatesFixture()['cases'];

        return $cases;
    }

    /**
     * The pinned snapshot with a case's overrides applied, so every case runs
     * through the production fromSnapshot(). Overridden n$-per-token rates
     * become USD-per-1M rate strings again (1 → "0.001").
     *
     * @param  PricingOverrides  $overrides
     * @return array<string, mixed>
     */
    private static function snapshotWith(array $overrides): array
    {
        $snapshotOverrides = ['tokens' => $overrides['tokens'] ?? []];

        foreach ($overrides['nanodollars_per_token'] ?? [] as $type => $nanodollarsPerToken) {
            $snapshotOverrides['rates'][$type] = (string) BigDecimal::ofUnscaledValue($nanodollarsPerToken, self::RATE_STRING_SCALE);
        }

        return self::pinnedPricingSnapshotWith($snapshotOverrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixturePricing(): array
    {
        /** @var array<string, mixed> $pricing */
        $pricing = self::estimatesFixture()['pricing'];

        return $pricing;
    }

    private static function action(PricingTable $pricing): EstimateGenerationCostAction
    {
        return new EstimateGenerationCostAction($pricing, new CalculateProviderCostAction, new CalculateUserChargeAction);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toFixtureShape(GenerationCostEstimate $estimate): array
    {
        return [
            'prompt_characters' => $estimate->promptCharacters,
            'lines' => array_map(fn (EstimateLine $line): array => [
                'exact' => $line->isExact,
                'tokens' => self::tokenBounds($line->tokens),
                'provider_nanodollars' => self::costBounds($line->providerCost),
            ], $estimate->lines),
            'provider_nanodollars' => self::costBounds($estimate->providerCost),
            'user_nanodollars' => self::costBounds($estimate->userPrice),
        ];
    }

    /**
     * @return array{min: int, typical: int, max: int}
     */
    private static function tokenBounds(TokenBounds $bounds): array
    {
        return ['min' => $bounds->min, 'typical' => $bounds->typical, 'max' => $bounds->max];
    }

    /**
     * @return array{min: int, typical: int, max: int}
     */
    private static function costBounds(CostBounds $bounds): array
    {
        return ['min' => $bounds->min->amount, 'typical' => $bounds->typical->amount, 'max' => $bounds->max->amount];
    }
}
