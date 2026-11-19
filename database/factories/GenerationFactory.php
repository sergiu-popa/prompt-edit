<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use LogicException;

/**
 * The definition is a pending row: PE-006's AC1 worked example, before submit.
 * Each state builds on the one before it, as Pixelforge moves a generation on.
 *
 * @extends Factory<Generation>
 */
final class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => 'A lighthouse on a cliff at dawn, in oils',
            'size' => ImageSize::Landscape,
            'quality' => ImageQuality::Medium,
            'reference_images' => ['https://example.com/style.png'],
            'user_estimate_min_nanodollars' => 95_237_500,
            'user_estimate_typical_nanodollars' => 109_312_500,
            'user_estimate_max_nanodollars' => 123_387_500,
        ];
    }

    /**
     * Accepted by Pixelforge: it has a provider id and Pixelforge's creation time.
     */
    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_' . strtoupper((string) Str::ulid()),
            'provider_created_at' => CarbonImmutable::now()->startOfSecond()->subSeconds(20),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn (array $attributes): array => [
            'status' => GenerationStatus::Processing,
            'started_at' => self::instant($attributes['provider_created_at'])->addSeconds(2),
        ]);
    }

    /**
     * Usage inside the definition's estimate (its typical price, in fact),
     * billed at the published rates.
     */
    public function succeeded(): static
    {
        return $this->processing()->state(function (array $attributes): array {
            $completedAt = self::instant($attributes['started_at'])->addSeconds(16);

            return [
                'status' => GenerationStatus::Succeeded,
                'input_text_tokens' => 10,
                'input_image_tokens' => 765,
                'output_tokens' => 1_650,
                'reasoning_tokens' => 1_100,
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'image_url' => sprintf('https://pixelforge.promptedit.dev/images/%s.png?expires=1&signature=factory', $attributes['provider_id']),
                'image_url_expires_at' => $completedAt->addDay(),
                'completed_at' => $completedAt,
            ];
        });
    }

    public function failed(): static
    {
        return $this->processing()->state(fn (array $attributes): array => [
            'status' => GenerationStatus::Failed,
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image for this prompt.',
            'completed_at' => self::instant($attributes['started_at'])->addSeconds(16),
        ]);
    }

    private static function instant(mixed $value): CarbonImmutable
    {
        if (! $value instanceof DateTimeInterface) {
            throw new LogicException('A generation state builds on an earlier state\'s instant.');
        }

        return CarbonImmutable::instance($value);
    }
}
