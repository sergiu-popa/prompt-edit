<?php

declare(strict_types=1);

namespace App\Domain\Generation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One verified Pixelforge webhook event, recorded once by its signed `id`
 * in the same transaction as the state change it caused (D-07). Pings,
 * unknown types and events for unknown generations are recorded too, with
 * no generation. The type is kept as received, so it stays a plain string.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property ?int $delivery_attempt from the unsigned header, so informational only
 * @property ?string $generation_id
 * @property CarbonImmutable $received_at
 */
#[DateFormat('Y-m-d H:i:sP')]
#[Fillable([
    'event_id',
    'type',
    'delivery_attempt',
    'generation_id',
])]
final class PixelforgeWebhookEvent extends Model
{
    public const string CREATED_AT = 'received_at';

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_attempt' => 'integer',
            'received_at' => 'immutable_datetime',
        ];
    }
}
