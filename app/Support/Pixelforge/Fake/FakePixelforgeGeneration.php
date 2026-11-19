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
 * snapshot is the generation object as it is now; the usage, outcome and
 * timings were all decided at create, so the lifecycle only plays them out.
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
])]
final class FakePixelforgeGeneration extends Model
{
    public function status(): GenerationStatus
    {
        $status = $this->snapshot['status'] ?? null;

        return GenerationStatus::from(is_string($status) ? $status : '');
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
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
