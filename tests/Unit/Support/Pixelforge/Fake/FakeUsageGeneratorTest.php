<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Pixelforge\Fake;

use App\Domain\Billing\Actions\CalculateProviderCostAction;
use App\Domain\Billing\Actions\CalculateUserChargeAction;
use App\Domain\Billing\Actions\EstimateGenerationCostAction;
use App\Domain\Billing\ValueObjects\PricingTable;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Fake\FakeUsageGenerator;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Concerns\InteractsWithPricing;

/**
 * The fake's usage must look like Pixelforge's and can never price above the
 * hold, because every count is drawn within the PE-002 estimate's bounds.
 * Every cost here is computed by the test itself, from the docs' rates.
 */
final class FakeUsageGeneratorTest extends TestCase
{
    use InteractsWithPricing;

    private const string LIGHTHOUSE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting';

    /**
     * n$ per token: the docs' USD per 1M tokens × 1,000.
     */
    private const array NANODOLLARS_PER_TOKEN = [
        'input_text_tokens' => 5_000,
        'input_image_tokens' => 10_000,
        'output_tokens' => 40_000,
        'reasoning_tokens' => 12_500,
    ];

    /**
     * The docs' output tokens by quality and size.
     */
    private const array OUTPUT_TOKENS = [
        'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
        'medium' => ['1024x1024' => 1_100, '1536x1024' => 1_650, '1024x1536' => 1_650],
        'high' => ['1024x1024' => 4_300, '1536x1024' => 6_450, '1024x1536' => 6_450],
    ];

    /**
     * The docs' reasoning range by quality.
     */
    private const array REASONING_TOKENS = [
        'low' => [0, 500],
        'medium' => [200, 2_000],
        'high' => [1_000, 8_000],
    ];

    private const string PINNED_KEY = '01kaf00000000000000000000a';

    public function test_a_lighthouse_always_uses_the_docs_counts_within_its_estimate(): void
    {
        $generator = self::generator();

        for ($run = 0; $run < 200; $run++) {
            $usage = $generator->generate(self::lighthouse(strtolower((string) Str::ulid())));

            $this->assertSame(765, $usage['input_image_tokens']);
            $this->assertSame(6_450, $usage['output_tokens']);
            $this->assertGreaterThanOrEqual(10, $usage['input_text_tokens']);
            $this->assertLessThanOrEqual(14, $usage['input_text_tokens']);
            $this->assertGreaterThanOrEqual(1_000, $usage['reasoning_tokens']);
            $this->assertLessThanOrEqual(8_000, $usage['reasoning_tokens']);
            $this->assertSame(self::sumOf($usage), $usage['total_tokens']);

            $providerCost = self::providerCostOf($usage);
            $this->assertGreaterThanOrEqual(278_200_000, $providerCost);
            $this->assertLessThanOrEqual(365_720_000, $providerCost);
            $this->assertGreaterThanOrEqual(347_750_000, self::chargeOf($usage));
            $this->assertLessThanOrEqual(457_150_000, self::chargeOf($usage));
        }
    }

    public function test_a_key_always_gives_the_same_exact_usage(): void
    {
        $this->assertSame(
            [
                'input_text_tokens' => 11,
                'input_image_tokens' => 765,
                'output_tokens' => 6_450,
                'reasoning_tokens' => 2_638,
                'total_tokens' => 9_864,
            ],
            self::generator()->generate(self::lighthouse(self::PINNED_KEY)),
        );
    }

    public function test_the_same_payload_twice_gives_equal_usage(): void
    {
        $payload = self::lighthouse(strtolower((string) Str::ulid()));

        $this->assertSame(self::generator()->generate($payload), self::generator()->generate($payload));
    }

    public function test_different_keys_give_different_usage(): void
    {
        $generator = self::generator();
        $usages = [];

        for ($run = 0; $run < 50; $run++) {
            $usages[] = json_encode($generator->generate(self::lighthouse(strtolower((string) Str::ulid()))), JSON_THROW_ON_ERROR);
        }

        $this->assertGreaterThan(1, count(array_unique($usages)));
    }

    /**
     * Any prompt, quality, size and reference count follows the docs' rules,
     * with keywords counted as prompt characters.
     */
    public function test_any_payload_follows_the_docs_rules_within_its_estimate(): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(hash('sha256', 'fake-usage-property', binary: true)));
        $generator = self::generator();
        $estimator = self::estimator();
        $alphabet = ['a', 'Z', ' ', '7', ',', 'é', 'ñ', 'Ü', '🎨', '👍🏽', '灯'];
        $keywords = ['[fail]', '[slow]', '[duplicate]', '[expire]', '[succeed]'];

        for ($run = 0; $run < 500; $run++) {
            $length = $randomizer->getInt(1, 4_000);
            $prompt = '';

            while (mb_strlen($prompt) < $length) {
                $prompt .= $alphabet[$randomizer->getInt(0, count($alphabet) - 1)];
            }

            $prompt = mb_substr($prompt, 0, $length);

            if ($randomizer->getInt(0, 3) === 0) {
                $prompt .= ' ' . $keywords[$randomizer->getInt(0, count($keywords) - 1)];
            }

            $quality = ImageQuality::cases()[$randomizer->getInt(0, 2)];
            $size = ImageSize::cases()[$randomizer->getInt(0, 2)];
            $references = array_fill(0, $randomizer->getInt(0, 4), 'https://example.com/ref.png');
            $payload = new CreateGenerationPayloadData(strtolower((string) Str::ulid()), $prompt, $size, $quality, $references);

            $usage = $generator->generate($payload);
            $estimated = intdiv(mb_strlen($prompt) + 3, 4);
            [$reasoningMin, $reasoningMax] = self::REASONING_TOKENS[$quality->value];

            $this->assertGreaterThanOrEqual(intdiv($estimated * 85, 100), $usage['input_text_tokens']);
            $this->assertLessThanOrEqual(intdiv($estimated * 115 + 99, 100), $usage['input_text_tokens']);
            $this->assertSame(765 * count($references), $usage['input_image_tokens']);
            $this->assertSame(self::OUTPUT_TOKENS[$quality->value][$size->value], $usage['output_tokens']);
            $this->assertGreaterThanOrEqual($reasoningMin, $usage['reasoning_tokens']);
            $this->assertLessThanOrEqual($reasoningMax, $usage['reasoning_tokens']);
            $this->assertSame(self::sumOf($usage), $usage['total_tokens']);

            $estimate = $estimator->execute($prompt, $quality, $size, count($references));
            $this->assertGreaterThanOrEqual($estimate->userPrice->min->amount, self::chargeOf($usage));
            $this->assertLessThanOrEqual($estimate->userPrice->max->amount, self::chargeOf($usage));
        }
    }

    /**
     * The estimate fixture both suites share bounds the charge, for every case
     * priced at the pinned rates.
     */
    public function test_every_pinned_fixture_case_is_charged_within_its_expected_price(): void
    {
        $generator = self::generator();
        $cases = self::estimatesFixture()['cases'];
        $this->assertIsArray($cases);
        $checked = 0;

        foreach ($cases as $case) {
            $this->assertIsArray($case);

            if (array_key_exists('pricing_overrides', $case)) {
                continue;
            }

            /** @var array{prompt: string, prompt_repeat?: int, quality: string, size: string, reference_image_count: int, expected: array{user_nanodollars: array{min: int, max: int}}} $case */
            $payload = new CreateGenerationPayloadData(
                strtolower((string) Str::ulid()),
                str_repeat($case['prompt'], $case['prompt_repeat'] ?? 1),
                ImageSize::from($case['size']),
                ImageQuality::from($case['quality']),
                array_fill(0, $case['reference_image_count'], 'https://example.com/ref.png'),
            );

            $charge = self::chargeOf($generator->generate($payload));

            $this->assertGreaterThanOrEqual($case['expected']['user_nanodollars']['min'], $charge);
            $this->assertLessThanOrEqual($case['expected']['user_nanodollars']['max'], $charge);
            $checked++;
        }

        $this->assertGreaterThan(10, $checked);
    }

    private static function generator(): FakeUsageGenerator
    {
        return new FakeUsageGenerator(self::estimator());
    }

    private static function estimator(): EstimateGenerationCostAction
    {
        return new EstimateGenerationCostAction(
            PricingTable::fromSnapshot(self::pinnedPricingSnapshot()),
            new CalculateProviderCostAction,
            new CalculateUserChargeAction,
        );
    }

    private static function lighthouse(string $generationId): CreateGenerationPayloadData
    {
        return new CreateGenerationPayloadData(
            $generationId,
            self::LIGHTHOUSE_PROMPT,
            ImageSize::Landscape,
            ImageQuality::High,
            ['https://example.com/style.png'],
        );
    }

    /**
     * @param  array<string, int>  $usage
     */
    private static function sumOf(array $usage): int
    {
        return $usage['input_text_tokens'] + $usage['input_image_tokens'] + $usage['output_tokens'] + $usage['reasoning_tokens'];
    }

    /**
     * @param  array<string, int>  $usage
     */
    private static function providerCostOf(array $usage): int
    {
        $cost = 0;

        foreach (self::NANODOLLARS_PER_TOKEN as $type => $rate) {
            $cost += $usage[$type] * $rate;
        }

        return $cost;
    }

    /**
     * ceil(provider × 125 ÷ 100).
     *
     * @param  array<string, int>  $usage
     */
    private static function chargeOf(array $usage): int
    {
        return intdiv(self::providerCostOf($usage) * 125 + 99, 100);
    }
}
