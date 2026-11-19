<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use LogicException;

/**
 * Ledger entries are append-only (D-08): a correction is a new entry, never a
 * change to an old one.
 */
final class ImmutableCreditLedgerEntryException extends LogicException
{
    public static function updateRefused(int $id): self
    {
        return new self(sprintf('Credit ledger entry %d is append-only and cannot be updated.', $id));
    }

    public static function deleteRefused(int $id): self
    {
        return new self(sprintf('Credit ledger entry %d is append-only and cannot be deleted.', $id));
    }
}
