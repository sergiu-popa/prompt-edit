<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Actions;

use App\Domain\Billing\Actions\CalculateProviderCostAction;
use App\Domain\Billing\Actions\CalculateUserChargeAction;
use App\Domain\Billing\Actions\EstimateGenerationCostAction;
use App\Domain\Billing\DataTransferObjects\EstimateLine;
use App\Domain\Billing\DataTransferObjects\GenerationCostEstimate;
use App\Domain\Billing\ValueObjects\CostBounds;
use App\Domain\Billing\ValueObjects\PricingTable;
use App\Domain\Billing\ValueObjects\TokenBounds;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
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
 *     expected: array<string, mixed>,
 * }
 */
final class EstimateGenerationCostActionTest extends TestCase
{
    use InteractsWithPricing;

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
        $estimate = self::action(PricingTable::fromSnapshot(self::pinnedPricingSnapshot()))->execute(
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

    /**
     * @return list<EstimateCase>
     */
    private static function cases(): array
    {
        /** @var list<EstimateCase> $cases */
        $cases = self::estimatesFixture()['cases'];

        return $cases;
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
