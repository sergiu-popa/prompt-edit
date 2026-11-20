<?php

declare(strict_types=1);

namespace App\Domain\Generation\Models;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Enums\SubmitRetryNote;
use App\Domain\Generation\QueryBuilders\GenerationQueryBuilder;
use App\Domain\Generation\ValueObjects\SubmitBookkeeping;
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
 * The `submit_*` columns are the submit job's record of its attempts (D-45). Only
 * the submit path writes them, never the transition action, and they are not
 * fillable.
 *
 * @property string $id
 * @property int $user_id
 * @property ?string $submission_token
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
 * @property ?CarbonImmutable $submit_first_attempted_at
 * @property ?CarbonImmutable $submit_last_attempted_at
 * @property ?SubmitAttemptOutcome $submit_last_outcome
 * @property ?CarbonImmutable $submit_ambiguous_at
 * @property ?CarbonImmutable $submit_handed_over_at
 * @property ?CarbonImmutable $submit_first_overloaded_at
 * @property ?CarbonImmutable $submit_first_concurrency_limited_at
 * @property int $submit_invalid_api_key_count
 * @property ?SubmitRetryNote $submit_retry_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @method static GenerationQueryBuilder query()
 */
#[DateFormat('Y-m-d H:i:sP')]
#[Fillable([
    'user_id',
    'submission_token',
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
     * Writes the submit bookkeeping in one UPDATE of the `submit_*` columns alone
     * (D-45), through the casts and the offset-carrying date format. It never
     * touches status, costs or `updated_at`, so it is never a transition.
     */
    public function recordSubmitBookkeeping(SubmitBookkeeping $bookkeeping): void
    {
        $columns = $bookkeeping->toAttributes();

        $this->forceFill($columns);
        $changed = array_intersect_key($this->getDirty(), $columns);

        if ($changed === []) {
            return;
        }

        self::query()->whereKey($this->getKey())->toBase()->update($changed);
        $this->syncOriginalAttributes(array_keys($changed));
    }

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
            'submit_first_attempted_at' => 'immutable_datetime',
            'submit_last_attempted_at' => 'immutable_datetime',
            'submit_last_outcome' => SubmitAttemptOutcome::class,
            'submit_ambiguous_at' => 'immutable_datetime',
            'submit_handed_over_at' => 'immutable_datetime',
            'submit_first_overloaded_at' => 'immutable_datetime',
            'submit_first_concurrency_limited_at' => 'immutable_datetime',
            'submit_invalid_api_key_count' => 'integer',
            'submit_retry_note' => SubmitRetryNote::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
