<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Fake;

use App\Domain\Generation\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One generation the fake Pixelforge accepted, keyed by its `gen_…` id. The
 * snapshot is the generation object as it is now, in the docs' field order;
 * the usage, outcome and timings were all decided at create, so the
 * lifecycle only plays them out. Each `mark…()` keeps that order and saves.
 * Rows only ever come from the fake's create, so there is no factory.
 * Dates are written with their UTC offset, as on `generations` (D-10).
 *
 * @property string $id
 * @property string $idempotency_key
 * @property string $request_hash
 * @property array<string, mixed> $snapshot
 * @property array<string, int> $usage
 * @property FakeGenerationOutcome $outcome
 * @property int $processing_seconds
 * @property int $image_ttl_seconds
 * @property bool $duplicates_webhooks
 * @property bool $drops_webhooks
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[DateFormat('Y-m-d H:i:sP')]
#[Table(keyType: 'string', incrementing: false)]
#[Fillable([
    'id',
    'idempotency_key',
    'request_hash',
    'snapshot',
    'usage',
    'outcome',
    'processing_seconds',
    'image_ttl_seconds',
    'duplicates_webhooks',
    'drops_webhooks',
])]
final class FakePixelforgeGeneration extends Model
{
    public function status(): GenerationStatus
    {
        $status = $this->snapshot['status'] ?? null;

        return GenerationStatus::from(is_string($status) ? $status : '');
    }

    public function markProcessing(CarbonImmutable $at): void
    {
        $this->snapshot = [
            ...$this->snapshot,
            'status' => GenerationStatus::Processing->value,
            'started_at' => $at->toIso8601ZuluString(),
        ];

        $this->save();
    }

    /**
     * The usage planned at create becomes the generation's own.
     */
    public function markSucceeded(CarbonImmutable $at, string $imageUrl, CarbonImmutable $expiresAt): void
    {
        $this->snapshot = [
            ...$this->snapshot,
            'status' => GenerationStatus::Succeeded->value,
            'image_url' => $imageUrl,
            'image_url_expires_at' => $expiresAt->toIso8601ZuluString(),
            'usage' => $this->usage,
            'completed_at' => $at->toIso8601ZuluString(),
        ];

        $this->save();
    }

    /**
     * The planned usage is never reported: a failed generation is not billed.
     */
    public function markFailed(CarbonImmutable $at): void
    {
        $this->snapshot = [
            ...$this->snapshot,
            'status' => GenerationStatus::Failed->value,
            'error' => $this->outcome->error(),
            'completed_at' => $at->toIso8601ZuluString(),
        ];

        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'usage' => 'array',
            'outcome' => FakeGenerationOutcome::class,
            'processing_seconds' => 'integer',
            'image_ttl_seconds' => 'integer',
            'duplicates_webhooks' => 'boolean',
            'drops_webhooks' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
