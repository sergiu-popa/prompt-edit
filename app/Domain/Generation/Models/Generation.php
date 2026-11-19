<?php

declare(strict_types=1);

namespace App\Domain\Generation\Models;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\QueryBuilders\GenerationQueryBuilder;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One image generation, keyed by the ULID that is also its Pixelforge
 * idempotency key and `metadata.generation_id` (D-05).
 *
 * Every date is written with its UTC offset, so the database session's
 * timezone never shifts a Pixelforge instant (D-10).
 *
 * The provider cost and the user charge stay null until the generation is
 * terminal, and only the transition action writes them, under its row lock.
 *
 * @property string $id
 * @property int $user_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property int $user_estimate_min_nanodollars
 * @property int $user_estimate_typical_nanodollars
 * @property int $user_estimate_max_nanodollars
 * @property ?string $provider_id
 * @property ?int $input_text_tokens
 * @property ?int $input_image_tokens
 * @property ?int $output_tokens
 * @property ?int $reasoning_tokens
 * @property ?array<string, string> $rates
 * @property ?int $provider_cost_nanodollars
 * @property ?int $user_charge_nanodollars
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?string $error_param
 * @property ?LocalFailureReason $local_failure_reason
 * @property ?string $image_url
 * @property ?CarbonImmutable $image_url_expires_at
 * @property ?CarbonImmutable $provider_created_at
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @method static GenerationQueryBuilder query()
 */
#[DateFormat('Y-m-d H:i:sP')]
#[Fillable([
    'user_id',
    'status',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'user_estimate_min_nanodollars',
    'user_estimate_typical_nanodollars',
    'user_estimate_max_nanodollars',
    'provider_id',
    'input_text_tokens',
    'input_image_tokens',
    'output_tokens',
    'reasoning_tokens',
    'rates',
    'provider_cost_nanodollars',
    'user_charge_nanodollars',
    'error_code',
    'error_message',
    'error_param',
    'local_failure_reason',
    'image_url',
    'image_url_expires_at',
    'provider_created_at',
    'started_at',
    'completed_at',
])]
#[UseEloquentBuilder(GenerationQueryBuilder::class)]
#[UseFactory(GenerationFactory::class)]
final class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'user_estimate_min_nanodollars' => 'integer',
            'user_estimate_typical_nanodollars' => 'integer',
            'user_estimate_max_nanodollars' => 'integer',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'rates' => 'array',
            'provider_cost_nanodollars' => 'integer',
            'user_charge_nanodollars' => 'integer',
            'local_failure_reason' => LocalFailureReason::class,
            'image_url_expires_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
