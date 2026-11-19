<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Enums;

use App\Domain\Generation\Enums\GenerationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GenerationStatusTest extends TestCase
{
    public function test_values_are_ours_then_pixelforges_in_lifecycle_order(): void
    {
        $this->assertSame(
            ['pending', 'queued', 'processing', 'succeeded', 'failed'],
            array_map(fn (GenerationStatus $status): string => $status->value, GenerationStatus::cases()),
        );
    }

    /**
     * @return array<string, array{GenerationStatus, int, bool, string}>
     */
    public static function statuses(): array
    {
        return [
            'pending' => [GenerationStatus::Pending, 0, false, 'Queued'],
            'queued' => [GenerationStatus::Queued, 1, false, 'Queued'],
            'processing' => [GenerationStatus::Processing, 2, false, 'Processing'],
            'succeeded' => [GenerationStatus::Succeeded, 3, true, 'Succeeded'],
            'failed' => [GenerationStatus::Failed, 3, true, 'Failed'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_each_status_has_its_rank_terminal_flag_and_label(
        GenerationStatus $status,
        int $rank,
        bool $isTerminal,
        string $label,
    ): void {
        $this->assertSame($rank, $status->rank());
        $this->assertSame($isTerminal, $status->isTerminal());
        $this->assertSame($label, $status->label());
    }

    public function test_ranks_only_move_forward_and_the_terminal_statuses_tie(): void
    {
        $this->assertLessThan(GenerationStatus::Queued->rank(), GenerationStatus::Pending->rank());
        $this->assertLessThan(GenerationStatus::Processing->rank(), GenerationStatus::Queued->rank());
        $this->assertLessThan(GenerationStatus::Succeeded->rank(), GenerationStatus::Processing->rank());
        $this->assertSame(GenerationStatus::Succeeded->rank(), GenerationStatus::Failed->rank());
    }
}
