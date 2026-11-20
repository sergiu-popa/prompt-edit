<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DataTransferObjects;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;

/**
 * One user's figures over the report's range.
 */
final readonly class ReportUserFigures
{
    public function __construct(
        public int $userId,
        public string $email,
        public ReportFigures $figures,
    ) {}

    /**
     * @return array{user_id: int, email: string, succeeded: int, spend_nanodollars: int, provider_cost_nanodollars: int, profit_nanodollars: int}
     *
     * @throws NanodollarOverflowException
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'email' => $this->email,
            ...$this->figures->toArray(),
        ];
    }
}
