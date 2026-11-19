<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The definition is a pending row: PE-006's AC1 worked example, before submit.
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
}
