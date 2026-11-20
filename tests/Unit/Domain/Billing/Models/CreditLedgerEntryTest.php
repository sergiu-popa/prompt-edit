<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Models;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Exceptions\ImmutableCreditLedgerEntryException;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
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

    private const string TOKEN = '6a1f9e2c-4b7d-4c3a-8e5f-2d9b0c7a1e36';

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

    public function test_top_ups_without_a_generation_never_collide_on_the_generation_index(): void
    {
        $user = User::factory()->create();

        foreach (['3f6c1a52-0d6b-4e43-9a51-6c2b8e0f7a11', '9b2e4d70-5c1f-4a8e-b3d6-1e7f0a9c2b44'] as $token) {
            CreditLedgerEntry::query()->create([
                'user_id' => $user->id,
                'type' => CreditLedgerEntryType::Purchase,
                'amount_nanodollars' => 1_000_000_000,
                'purchase_token' => $token,
            ]);
        }

        $this->assertSame(2, DB::table('credit_ledger_entries')->whereNull('generation_id')->count());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rowsTheChecksRefuse(): array
    {
        return [
            'a hold without a generation' => [['type' => 'hold', 'amount_nanodollars' => -1]],
            'a release without a generation' => [['type' => 'release', 'amount_nanodollars' => 1]],
            'a charge without a generation' => [['type' => 'charge', 'amount_nanodollars' => -1]],
            'a grant with a generation' => [['type' => 'grant', 'amount_nanodollars' => 1, 'generation_id' => true]],
            'a purchase with a generation' => [['type' => 'purchase', 'amount_nanodollars' => 1, 'purchase_token' => self::TOKEN, 'generation_id' => true]],
            'a purchase without a token' => [['type' => 'purchase', 'amount_nanodollars' => 1]],
            'a grant with a token' => [['type' => 'grant', 'amount_nanodollars' => 1, 'purchase_token' => self::TOKEN]],
            'a hold with a token' => [['type' => 'hold', 'amount_nanodollars' => -1, 'purchase_token' => self::TOKEN, 'generation_id' => true]],
            'a zero grant' => [['type' => 'grant', 'amount_nanodollars' => 0]],
            'a negative grant' => [['type' => 'grant', 'amount_nanodollars' => -1]],
            'a zero purchase' => [['type' => 'purchase', 'amount_nanodollars' => 0, 'purchase_token' => self::TOKEN]],
            'a negative purchase' => [['type' => 'purchase', 'amount_nanodollars' => -1, 'purchase_token' => self::TOKEN]],
            'an unknown type' => [['type' => 'refund', 'amount_nanodollars' => 1]],
        ];
    }

    /**
     * Raw inserts, so the database alone decides (D-67). `generation_id => true`
     * stands for a real generation, made here.
     *
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('rowsTheChecksRefuse')]
    public function test_the_database_refuses_an_entry_its_checks_forbid(array $row): void
    {
        $generation = Generation::factory()->create();

        if (($row['generation_id'] ?? null) === true) {
            $row['generation_id'] = $generation->id;
        }

        $this->assertInsertRefused('23514', ['user_id' => $generation->user_id, ...$row]);
    }

    public function test_a_user_has_at_most_one_grant_and_another_users_grant_is_fine(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->insertEntry(['user_id' => $first->id, 'type' => 'grant', 'amount_nanodollars' => 1_000_000_000]);
        $this->insertEntry(['user_id' => $second->id, 'type' => 'grant', 'amount_nanodollars' => 1_000_000_000]);

        $this->assertInsertRefused('23505', ['user_id' => $first->id, 'type' => 'grant', 'amount_nanodollars' => 5]);
        $this->assertSame(2, DB::table('credit_ledger_entries')->where('type', 'grant')->count());
    }

    public function test_a_purchase_token_is_unique_per_user_and_another_user_may_repeat_it(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $purchase = ['type' => 'purchase', 'amount_nanodollars' => 1_000_000_000, 'purchase_token' => self::TOKEN];
        $this->insertEntry(['user_id' => $first->id, ...$purchase]);
        $this->insertEntry(['user_id' => $second->id, ...$purchase]);

        $this->assertInsertRefused('23505', ['user_id' => $first->id, ...$purchase, 'amount_nanodollars' => 5_000_000_000]);
        $this->assertSame(2, DB::table('credit_ledger_entries')->where('purchase_token', self::TOKEN)->count());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function topUps(): array
    {
        return [
            'grant' => [['type' => 'grant', 'amount_nanodollars' => 1_000_000_000]],
            'purchase' => [['type' => 'purchase', 'amount_nanodollars' => 1_000_000_000, 'purchase_token' => self::TOKEN]],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('topUps')]
    public function test_top_ups_are_append_only_too(array $row): void
    {
        $user = User::factory()->create();
        $this->insertEntry(['user_id' => $user->id, ...$row]);
        $entry = CreditLedgerEntry::query()->where('user_id', $user->id)->sole();

        try {
            DB::transaction(fn (): int => DB::table('credit_ledger_entries')->where('id', $entry->id)->update(['amount_nanodollars' => 2]));
            $this->fail('A raw write changed the top-up.');
        } catch (QueryException $exception) {
            $this->assertSame('23001', $exception->getCode());
        }

        $this->expectException(ImmutableCreditLedgerEntryException::class);

        $entry->delete();
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

    /**
     * @return array<string, array{Closure(Builder): int}>
     */
    public static function rawWrites(): array
    {
        return [
            'update' => [fn (Builder $entries): int => $entries->update(['amount_nanodollars' => 0])],
            'delete' => [fn (Builder $entries): int => $entries->delete()],
        ];
    }

    /**
     * The raw write runs in a nested transaction, so its savepoint is rolled
     * back and the test's own transaction stays usable after Postgres refuses it.
     *
     * @param  Closure(Builder): int  $write
     */
    #[DataProvider('rawWrites')]
    public function test_the_database_refuses_a_raw_write_that_bypasses_the_model(Closure $write): void
    {
        $entry = $this->givenAnEntry();
        $before = $this->rawEntryRow($entry);

        try {
            DB::transaction(fn (): int => $write(DB::table('credit_ledger_entries')->where('id', $entry->id)));
            $this->fail('A raw write changed the entry.');
        } catch (QueryException $exception) {
            $this->assertSame('23001', $exception->getCode());
            $this->assertStringContainsString(sprintf('Credit ledger entry %d is append-only', $entry->id), $exception->getMessage());
        }

        $this->assertSame($before, $this->rawEntryRow($entry));
    }

    /**
     * The factory holds a created generation's max user price, as the create action does.
     */
    private function givenAnEntry(): CreditLedgerEntry
    {
        $generation = Generation::factory()->create(['user_estimate_max_nanodollars' => 457_150_000]);

        return CreditLedgerEntry::query()->where('generation_id', $generation->id)->sole();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertEntry(array $row): void
    {
        DB::table('credit_ledger_entries')->insert([...$row, 'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:sP')]);
    }

    /**
     * The insert runs in a nested transaction, so its savepoint is rolled back
     * and the test's own transaction stays usable after Postgres refuses it.
     *
     * @param  array<string, mixed>  $row
     */
    private function assertInsertRefused(string $sqlState, array $row): void
    {
        $before = DB::table('credit_ledger_entries')->count();

        try {
            DB::transaction(fn () => $this->insertEntry($row));
            $this->fail('The database stored a forbidden entry.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->getCode());
        }

        $this->assertSame($before, DB::table('credit_ledger_entries')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function rawEntryRow(CreditLedgerEntry $entry): array
    {
        return (array) DB::table('credit_ledger_entries')->where('id', $entry->id)->first();
    }
}
