<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Exceptions\ImmutableCreditLedgerEntryException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One signed movement of a user's credit, in integer nanodollars (D-03, D-08).
 * Entries are append-only: the model refuses to update or delete one. Raw
 * query-builder writes bypass this guard, so nothing in the app makes them.
 *
 * @property int $id
 * @property int $user_id
 * @property ?string $generation_id
 * @property CreditLedgerEntryType $type
 * @property int $amount_nanodollars
 * @property CarbonImmutable $created_at
 */
#[DateFormat('Y-m-d H:i:sP')]
#[Fillable([
    'user_id',
    'generation_id',
    'type',
    'amount_nanodollars',
])]
final class CreditLedgerEntry extends Model
{
    public const null UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(function (self $entry): never {
            throw ImmutableCreditLedgerEntryException::updateRefused($entry->id);
        });

        self::deleting(function (self $entry): never {
            throw ImmutableCreditLedgerEntryException::deleteRefused($entry->id);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditLedgerEntryType::class,
            'amount_nanodollars' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
