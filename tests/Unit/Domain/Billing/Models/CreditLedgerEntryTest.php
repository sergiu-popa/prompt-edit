<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Models;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Exceptions\ImmutableCreditLedgerEntryException;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CreditLedgerEntryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{CreditLedgerEntryType}>
     */
    public static function generationEntryTypes(): array
    {
        return [
            'hold' => [CreditLedgerEntryType::Hold],
            'release' => [CreditLedgerEntryType::Release],
            'charge' => [CreditLedgerEntryType::Charge],
        ];
    }

    /**
     * The insert that violates the index is the last statement: Postgres
     * aborts the rest of the transaction after it.
     */
    #[DataProvider('generationEntryTypes')]
    public function test_the_database_refuses_a_second_entry_of_one_type_for_a_generation(CreditLedgerEntryType $type): void
    {
        $generation = Generation::factory()->create();
        CreditLedgerEntry::query()->firstOrCreate(
            ['generation_id' => $generation->id, 'type' => $type],
            ['user_id' => $generation->user_id, 'amount_nanodollars' => -1],
        );

        $this->expectException(UniqueConstraintViolationException::class);

        CreditLedgerEntry::query()->create([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => $type,
            'amount_nanodollars' => -1,
        ]);
    }

    public function test_entries_without_a_generation_never_collide(): void
    {
        $user = User::factory()->create();

        foreach ([1, 2] as $amount) {
            CreditLedgerEntry::query()->create([
                'user_id' => $user->id,
                'generation_id' => null,
                'type' => CreditLedgerEntryType::Release,
                'amount_nanodollars' => $amount,
            ]);
        }

        $this->assertSame(2, DB::table('credit_ledger_entries')->whereNull('generation_id')->count());
    }

    public function test_an_entry_for_an_unknown_generation_is_refused(): void
    {
        $user = User::factory()->create();

        try {
            CreditLedgerEntry::query()->create([
                'user_id' => $user->id,
                'generation_id' => '01jc2z8q4n6v0m3h7x5t9r2b1k',
                'type' => CreditLedgerEntryType::Hold,
                'amount_nanodollars' => -1,
            ]);
            $this->fail('An entry for an unknown generation was written.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }
    }

    public function test_an_entry_reads_back_with_its_casts_and_a_creation_time(): void
    {
        $this->freezeSecond();
        $entry = $this->givenAnEntry();

        $readBack = CreditLedgerEntry::query()->findOrFail($entry->id);

        $this->assertSame(CreditLedgerEntryType::Hold, $readBack->type);
        $this->assertSame(-457_150_000, $readBack->amount_nanodollars);
        $this->assertInstanceOf(CarbonImmutable::class, $readBack->created_at);
        $this->assertTrue($readBack->created_at->equalTo(CarbonImmutable::now()));
        $this->assertFalse(Schema::hasColumn('credit_ledger_entries', 'updated_at'));
    }

    public function test_updating_an_entry_throws_and_leaves_the_row_unchanged(): void
    {
        $entry = $this->givenAnEntry();
        $before = $this->rawEntryRow($entry);

        try {
            $entry->update(['amount_nanodollars' => 0]);
            $this->fail('The entry was updated.');
        } catch (ImmutableCreditLedgerEntryException $exception) {
            $this->assertSame(sprintf('Credit ledger entry %d is append-only and cannot be updated.', $entry->id), $exception->getMessage());
        }

        $this->assertSame($before, $this->rawEntryRow($entry));
    }

    public function test_saving_a_changed_entry_throws_and_leaves_the_row_unchanged(): void
    {
        $entry = $this->givenAnEntry();
        $before = $this->rawEntryRow($entry);
        $entry->type = CreditLedgerEntryType::Release;

        $this->expectException(ImmutableCreditLedgerEntryException::class);

        try {
            $entry->save();
        } finally {
            $this->assertSame($before, $this->rawEntryRow($entry));
        }
    }

    public function test_deleting_an_entry_throws_and_leaves_the_row_unchanged(): void
    {
        $entry = $this->givenAnEntry();
        $before = $this->rawEntryRow($entry);

        try {
            $entry->delete();
            $this->fail('The entry was deleted.');
        } catch (ImmutableCreditLedgerEntryException $exception) {
            $this->assertSame(sprintf('Credit ledger entry %d is append-only and cannot be deleted.', $entry->id), $exception->getMessage());
        }

        $this->assertSame($before, $this->rawEntryRow($entry));
    }

    private function givenAnEntry(): CreditLedgerEntry
    {
        $user = User::factory()->create();

        return CreditLedgerEntry::query()->create([
            'user_id' => $user->id,
            'generation_id' => null,
            'type' => CreditLedgerEntryType::Hold,
            'amount_nanodollars' => -457_150_000,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawEntryRow(CreditLedgerEntry $entry): array
    {
        return (array) DB::table('credit_ledger_entries')->where('id', $entry->id)->first();
    }
}
