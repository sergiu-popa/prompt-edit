# Plan — PE-007-credit-ledger-hold-settle-release

## Approach
A new append-only `credit_ledger_entries` table in Billing holds signed n$ entries. Its unique `(generation_id, type)` index is the second layer of exactly-once. `CreateGenerationAction` writes the hold of −max in its own transaction. `TransitionGenerationAction` prices a succeeded snapshot from that snapshot's own rates before it writes anything. It stores the provider cost and user charge in the same conditional UPDATE as the status, then calls one Billing action. That action releases the exact negation of the stored hold and writes the charge, and from task 8 it does both with `INSERT … ON CONFLICT DO NOTHING`. A failure, from the provider or local, stores 0 / 0 and only releases. A provider success over a local failure (D-09) records the provider cost and usage but never charges.

## Tasks
Each task is one TDD commit: write the failing test first, then the code, then run `chain-make.sh <worktree> check` and get it green before committing. Tasks 1–7 cover AC1–AC14, AC17 and AC19. Tasks 8–11 cover AC15, AC16, AC18 and AC20. The cut point is after task 7, as the story suggests (see Risks).

- [ ] 1. Add the `credit_ledger_entries` migration, `CreditLedgerEntryType`, `CreditLedgerEntry` with its append-only guard, and `ImmutableCreditLedgerEntryException`. Test-first for AC14, AC19 and the foreign-key half of AC3. Commit: `[PE-007] Add the append-only credit ledger`
- [ ] 2. Add `HoldGenerationCreditsAction`, call it from `CreateGenerationAction`, and make `GenerationFactory` hold every row it creates. Add `InteractsWithCreditLedger`, `createGenerationFor()` and `givenTheLighthouseGeneration()`. Test-first for AC1, AC2, the rollback half of AC3 and the factory's holds. Commit: `[PE-007] Hold the max estimate when a generation is created`
- [ ] 3. Add the cost-columns migration and `SettleGenerationCreditsAction` (plain inserts for now). Make the transition action price and settle a succeeded `Applied`, set the factory's `succeeded()` costs and ledger, and add `lighthouseSucceededSnapshotFor()`. Test-first for AC4, AC5, the amounts of AC6–AC8, AC9 and the factory's succeeded ledger. Commit: `[PE-007] Charge the actual and release the hold on success`
- [ ] 4. Log the above-hold warning in `SettleGenerationCreditsAction`, and add `CapturesLogMessages`, moving the conflicts test onto it. Test-first by adding a "warned" column to task 3's data provider: AC8 warns once, and AC5–AC7 (AC6 is the equal-to-hold boundary) never warn. Commit: `[PE-007] Warn when a charge exceeds its hold`
- [ ] 5. Store 0 / 0 and release only when a snapshot or a local failure applies `failed`, and set the factory's `failed()` and `failedLocally()` costs and ledger. Test-first for AC10, AC11 and the factory's failed ledgers. Commit: `[PE-007] Release the hold without a charge when a generation fails`
- [ ] 6. Pin AC12 and AC13, and extend both AC8 pair tables in `TransitionGenerationActionTest` to assert that the ledger is unchanged on every pair that is not applied. No code change is expected. Commit: `[PE-007] Pin that a terminal generation's money never changes`
- [ ] 7. Pin AC17 with a failing ledger insert for each terminal status. No code change is expected. Commit: `[PE-007] Prove a failed settlement write rolls the transition back`
- [ ] 8. Switch both settlement writes to `fillAndInsertOrIgnore`, log each ignored insert, and record the decision (next free D-number) in `requirements.md` §5. Test-first for AC15. Commit: `[PE-007] Tolerate ledger duplicates left by a bypassed status guard`
- [ ] 9. Log, then rethrow, when pricing a succeeded snapshot fails, and pin the missing-usage case at the parser. Test-first for AC18. Commit: `[PE-007] Refuse and log snapshots that cannot be priced exactly`
- [ ] 10. Record the D-09 provider cost on an `IgnoredConflict` over a locally failed row, and update the existing conflicts test that expected an untouched row. Test-first for AC20. Commit: `[PE-007] Record provider cost for a success after a local failure`
- [ ] 11. Add ledger assertions to the two-process test, and add the succeeded-against-failed race. No code change is expected. Commit: `[PE-007] Prove concurrent terminal snapshots settle exactly once`

## Domain
All classes are `final`, and every file declares `strict_types`. Billing never imports a Generation class. Its actions take ids and `Nanodollars`, which keeps the existing dependency direction (Generation → Billing).

| Class / file | Location | Role |
| --- | --- | --- |
| `CreditLedgerEntryType` | `app/Domain/Billing/Enums/` | String-backed: `Hold` = `hold`, `Release` = `release`, `Charge` = `charge`. PE-017 appends `grant` and `purchase`. |
| `CreditLedgerEntry` | `app/Domain/Billing/Models/` | `#[Fillable]` for `user_id`, `generation_id`, `type` and `amount_nanodollars`. `UPDATED_AT = null`. `#[DateFormat('Y-m-d H:i:sP')]` gives the same exact instants as `Generation`. Casts: the enum, `integer` and `immutable_datetime`. In `booted()`, the `updating` and `deleting` listeners throw. There are no relations and no factory: entries are written only by the two actions below, and the bypass tests use `create()`. |
| `ImmutableCreditLedgerEntryException` | `app/Domain/Billing/Exceptions/` | Extends `\LogicException`. `updateRefused(int $id)` and `deleteRefused(int $id)`. |
| `HoldGenerationCreditsAction` | `app/Domain/Billing/Actions/` | `execute(int $userId, string $generationId, Nanodollars $maxUserPrice): void` writes one hold of −max with a plain `create()`. A row that was just created cannot already have a hold, so a duplicate means a bug and should fail loudly. Consumers: `CreateGenerationAction` and `GenerationFactory`. |
| `SettleGenerationCreditsAction` | `app/Domain/Billing/Actions/` | `execute(string $generationId, ?Nanodollars $userCharge): void`, where null means failed (no charge). It reads the generation's hold with `sole()`, so a missing hold throws and rolls back. It writes a release equal to the exact negation of that entry's amount, owned by the hold's `user_id`. When a charge is given, it writes −charge. When the charge is above the hold's magnitude, it logs a `Log::warning` with `generation_id`, `hold_nanodollars` and `charge_nanodollars` (task 4). From task 8, each write is a `fillAndInsertOrIgnore`. An insert that affects 0 rows logs `Log::error` with `generation_id` and `type`. It opens no transaction, because it relies on the caller's transaction and row lock. Consumers: `TransitionGenerationAction` and `GenerationFactory`. |
| `Generation` | `app/Domain/Generation/Models/` | Adds `provider_cost_nanodollars` and `user_charge_nanodollars` to `#[Fillable]`, with `integer` casts and `?int` `@property` lines. |
| `CreateGenerationAction` | `app/Domain/Generation/Actions/` | Injects `HoldGenerationCreditsAction`. Inside its existing transaction, after `create()`, it holds the created row's `user_estimate_max_nanodollars`. It reads that stored value rather than the estimate object (AC1). |
| `TransitionGenerationAction` | `app/Domain/Generation/Actions/` | Injects `CalculateProviderCostAction`, `CalculateUserChargeAction` and `SettleGenerationCreditsAction`. The outcome classification is unchanged. The rows below cover the money steps. |
| ↳ price (private) | | Runs `TokenRates::from()` on the snapshot's rates, then computes the provider cost and the user charge. `usage` and `rates` are narrowed with `?? throw InvalidGenerationSnapshotException::incompleteSuccess(…)`. This is for the type checker only, because `from()` already guarantees both on a succeeded snapshot. From task 9, an `InvalidRateException` or `NanodollarOverflowException` is caught, logged with `Log::error` (`generation_id`, `provider_id` = the snapshot id, `error` = the message) and rethrown. It is always called before any write. |
| ↳ write (private) | | Extracted from PE-006's `apply()` with no change in behaviour: fill, `updateTimestamps()`, the UPDATE conditional on the status read, then `syncOriginal()`. Used by apply and by D-09. |
| ↳ apply | | A succeeded `Applied` adds both prices to the attributes. A failed `Applied` (snapshot or local) adds 0 and 0. After the write, a terminal status calls settle: with the charge for succeeded, null for failed. Queued and processing applies stay exactly as they are. |
| ↳ D-09 (task 10) | | Runs on `IgnoredConflict` when the incoming status is succeeded, `local_failure_reason` is set and `output_tokens` is still null (nothing recorded yet, so a replay writes nothing). It prices the snapshot, then writes `provider_cost_nanodollars`, the four usage counts, `rates` and `completed_at`. The user charge stays 0 and there is no ledger call. The outcome is still `IgnoredConflict`. PE-006's conflict log, with `generation_id` and `incoming_provider_id`, is the anomaly log, so no second log is added. |
| `GenerationFactory` | `database/factories/` | In `configure()`, `afterCreating` holds the row's max, and for a terminal row it settles: `succeeded` with its `user_charge_nanodollars`, `failed` with null. `succeeded()` sets the costs to 87,450,000 / 109,312,500 (its usage at published rates, and the definition's typical price). `failed()` and `failedLocally()` set 0 / 0. Every factory row then has a ledger consistent with its status, which PE-012, PE-016 and PE-017 will read. |

## Data model
Both migrations are made with `php artisan make:migration`. Postgres 17.

**`create_credit_ledger_entries_table`**

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `id()` | Internal and never sent anywhere, so a sequence is fine here. |
| `user_id` | `foreignId()->constrained()->restrictOnDelete()` | The owner. No extra index; PE-017 adds one for balances. |
| `generation_id` | `foreignUlid()->nullable()->constrained()->restrictOnDelete()` | Null for PE-017's grants and purchases. The foreign key means a hold never exists without its generation (AC3). |
| `type` | string | `CreditLedgerEntryType`. No native enum and no CHECK, so PE-017 appends cases without a migration. |
| `amount_nanodollars` | bigInteger | Signed: a hold and a charge are below 0, a release above 0. |
| `created_at` | `timestampTz()`, not null | Stamped by the model, with the offset. There is no `updated_at` (AC19). |
| unique `(generation_id, type)` | | At most one entry of each type per generation (AC14). Postgres treats NULLs as distinct, so entries without a generation never collide. |

**`add_costs_to_generations_table`**: `provider_cost_nanodollars` and `user_charge_nanodollars`, both bigint and nullable. They are null until the generation is terminal (AC4), and 0 / 0 on failed. D-09 sets only the provider cost. No backfill is needed, because no real generations exist before PE-011.

## Decisions log (requirements §5)
Use the next free D-number after the highest one in `requirements.md` at commit time. That is D-25 if PE-006 adds nothing more. The row is added in task 8's commit.

| # | Decision | Why |
| --- | --- | --- |
| D-next | The release and charge a transition writes are inserted with `ON CONFLICT DO NOTHING` (Eloquent `fillAndInsertOrIgnore`), and an ignored insert is logged at error level. The hold uses a plain insert. | Postgres aborts the whole transaction on a unique violation, so a duplicate would roll back the status change and turn a webhook into a 500. A savepoint per insert adds round trips for the same result. In normal operation the status guard makes a conflict impossible, so one firing means a guard was bypassed, and the log makes that visible. A duplicate hold on a row that was just created is a bug and should fail loudly. |

## Tests
Every file lives in `tests/Unit`, mirroring `app/`, and is DB-backed with `RefreshDatabase` like PE-006's action tests (the project's layer table puts Actions under PHP unit). They are the story's "PHP feature (DB-backed)" layer. Every amount is asserted with `assertSame` on an exact int. Tests that assert "writes nothing" travel 1 minute before acting and compare `rawGenerationRow()` and `ledgerEntriesFor()` before and after. A `usage` override replaces the whole object, so give the exact `total_tokens` or leave it out. Reload the row between snapshots, so that `generationObjectFor()` echoes the stored provider id.

Shared test helpers (`tests/Concerns/`):
- `InteractsWithCreditLedger` (new):
  - `ledgerEntriesFor(Generation)` returns `[type, amount]` pairs ordered by `id`.
  - `ledgerNetFor(Generation)` reads `coalesce(sum(amount_nanodollars), 0)::bigint`, so the net arrives as an int, never as a numeric string or a float.
- `InteractsWithGenerations`:
  - `createGenerationFor(User, NewGenerationData)` replaces `CreateGenerationActionTest`'s private helper.
  - `givenTheLighthouseGeneration()` creates the docs example through the action for a new user: `A lighthouse on a cliff at dawn, oil painting`, high, 1536x1024, one ref.
- `InteractsWithGenerationSnapshots`: `lighthouseSucceededSnapshotFor(Generation)` is `snapshotFor()` with the docs example's usage of 12 / 765 / 6,450 / 3,184 (total 10,411) and the published rates.
- `CapturesLogMessages` (new): `setUpCapturesLogMessages()` listens for `MessageLogged`, and `loggedMessages()` returns them. Used by the settlement, exactly-once and conflicts tests. The console tests keep their own copies (out of scope).

| Test | Covers |
| --- | --- |
| `Domain/Billing/Models/CreditLedgerEntryTest` | **AC14:** one data row per type. A second entry of that type for the same generation, through `create()`, raises `UniqueConstraintViolationException`; it is the last statement, because Postgres then aborts the transaction. Two entries of one type with a null generation both insert. **AC19:** `update()`, a dirty `save()` and `delete()` each throw `ImmutableCreditLedgerEntryException`, and the raw row is unchanged. `created_at` is set, and `Schema::hasColumn(…, 'updated_at')` is false. **AC3:** an entry with an unknown `generation_id` raises a foreign-key violation. |
| `Domain/Generation/Actions/CreateGenerationActionTest` (+) | **AC1:** the Lighthouse ledger is exactly `[hold, −457,150,000]`, equal to the stored max, and owned by the user. **AC2:** a 400-code-point prompt (assert `mb_strlen`), high, 1536x1024, 2 refs holds −467,343,750. **AC3:** a throwing `CreditLedgerEntry::created` listener leaves no generation and no entry. **AC4:** both cost columns are added to `PROVIDER_COLUMNS`, so they are null on a new row. |
| `Domain/Generation/Actions/TransitionGenerationActionSettlementTest` (new) | **AC4:** queued, then processing, each with the costs still null and the ledger still `[hold]`. **AC5–AC8:** one data provider with the request, the usage and the rates, expecting the hold, provider cost, charge, entries, net and (from task 4) whether a warning was logged. The rows and values are below. **AC9:** after creation, raw-update the stored max and override `config('pricing')` rates. The release is still +457,150,000. **AC10:** one data row each for `generation_failed` and `internal_error`, with usage null: costs 0 / 0, `[hold, release +457,150,000]`, net 0. **AC11:** a local failure on the pending Lighthouse gives the same result. |
| `Domain/Generation/Actions/TransitionGenerationActionExactlyOnceTest` (new) | **AC12:** succeeded twice and failed twice. The second call is `IgnoredDuplicate`, nothing is thrown, and the row and ledger are unchanged. **AC13:** a late processing after succeeded, failed after succeeded, and succeeded after a provider failure each leave the row and ledger unchanged. **AC17:** a data provider pairs succeeded with a failing charge insert and failed with a failing release insert. A `DB::listen` throws after the insert whose SQL starts `insert into "credit_ledger_entries"` and whose bindings carry that type. The raw row is unchanged, still non-terminal with null costs, and the ledger is `[hold]`. **AC15 (task 8):** on the pending Lighthouse, `create()` a release and a charge, then apply the succeeded snapshot. It is `Applied` with no exception, a follow-up query in the same transaction succeeds, there is exactly one of each type, and there are two error logs. **AC18 (task 9):** an input-text rate of `"5.0001"` throws `InvalidRateException`, logs once at error level with the generation id, and changes nothing. A `usage: null` object throws `InvalidGenerationSnapshotException` from `GenerationSnapshot::from()`, and nothing changes. |
| `…ConflictsTest` (+) | **AC20 (task 10):** fail the Lighthouse locally, then apply `lighthouseSucceededSnapshotFor()`. It stays `failed` with a user charge of 0, its provider cost is 305,510,000, and its usage, rates and `completed_at` (`2026-11-18T23:59:59Z`) are recorded. The ledger is `[hold, release]` and the net is 0. The single conflict log carries `generation_id` and `incoming_provider_id`. A second apply leaves the raw row and the ledger unchanged. The existing locally failed test asserts the recording instead of an untouched row. |
| `…ConcurrencyTest` (+) | **AC16 (task 11):** the existing same-snapshot test also asserts one release and one charge, with a net of −109,312,500. A new test races a succeeded child against a failed child. The outcomes are exactly {`applied`, `ignored_conflict`}, and there is one release. The ledger has a charge if and only if the row is succeeded, and the costs match the winner: 87,450,000 / 109,312,500 for succeeded, 0 / 0 for failed. The child script is unchanged. |
| `…TransitionGenerationActionTest` (+) | Both AC8 pair tables (task 6) also compare `ledgerEntriesFor()` before and after on every pair that is not applied. |
| `Database/Factories/GenerationFactoryTest` (+) | Each state's costs and ledger. Pending, queued and processing: null costs and `[hold −123,387,500]`. Succeeded: `[hold, release +123,387,500, charge −109,312,500]`. Failed and locally failed: 0 / 0 and `[hold, release]`. |

The settlement rows (AC5–AC8):

| Row | Request | Usage, rates | Hold | Provider | Charge | Net | Warned |
| --- | --- | --- | --- | --- | --- | --- | --- |
| AC5 Lighthouse | `givenTheLighthouseGeneration()` | 12 / 765 / 6,450 / 3,184; published rates | −457,150,000 | 305,510,000 | 381,887,500 | −381,887,500 | no |
| AC6 docs maximum | the AC2 request | 115 / 1,530 / 6,450 / 8,000; published rates | −467,343,750 | 373,875,000 | 467,343,750 | −467,343,750 | no (the boundary) |
| AC7 rounding | 50 code points, low, 1024x1024, no refs | 13 / 0 / 280 / 250; `5.001` / `10.00` / `40.00` / `12.50` | −21,906,250 | 14,390,013 | 17,987,517 | −17,987,517 | no |
| AC8 above the hold | the AC7 request | the same usage; `5.00` / `10.00` / `80.00` / `12.50` | −21,906,250 | 25,590,000 | 31,987,500 | −31,987,500 | once, with the id, 21,906,250 and 31,987,500 |

Every max estimate above was checked against `EstimateGenerationCostAction` in the chain container: 365,720,000 / 457,150,000, 373,875,000 / 467,343,750, and 17,525,000 / 21,906,250.

No Billing action gets its own unit suite: every branch is reached through the Generation action tests above. JS unit, JS integration and E2E: none, because there is no UI.

## Risks
- **PR size is over the ≈400-line budget.** The estimate is ≈1,600 changed lines, excluding `.agent-workflow`: ≈450 production and ≈1,150 tests and helpers. The story's 450–500 leaves out most of the tests. One PR per story is the rule even above the budget (`.agent-workflow/CLAUDE.md`, decided 2026-11-19), so this ships as one PR, and the PR description names the cut point in case the user asks for a split.
  - The cut is after task 7.
    - PR A (tasks 1–7, ≈1,100) holds AC1–AC14, AC17 and AC19.
    - PR B (tasks 8–11, ≈500) holds AC15, AC16, AC18 and AC20.
  - PR A is safe on its own: a bypassed guard would raise a unique violation (a 500 that is retried), never a double charge.
- **The missing-usage half of AC18 is caught at the boundary.** PE-006's parser already rejects a succeeded object without usage (`incompleteSuccess('usage')`), so it never reaches settlement. This story pins that the object throws and nothing changes. Logging the generation id for an object that will not parse is the parsing caller's job, because this story has none. PE-008, PE-009 and PE-014 must log `metadata.generation_id` when `GenerationSnapshot::from()` throws.
- **An unpriceable snapshot never settles.** Every redelivery and every poll throws again, and the row stays non-terminal with its hold. PE-008 and PE-014 must not hot-loop on it. The operator follows the error log.
- **D-09 writes on an ignored outcome.** This is the one exception to PE-006's AC9, and the outcome stays `IgnoredConflict`. PE-014 still stamps only on `Applied`.
- **The factory now writes ledger entries.** Any later test that counts entries includes the factory's. `restrictOnDelete` means users and generations with entries cannot be deleted; nothing deletes them today.
- **Raw writes bypass the append-only guard.** Nothing in the app may update or delete ledger rows through the query builder. Once PE-017 adds a type without a generation, it could add a CHECK that hold, release and charge always carry a generation. Today that would make the null half of AC14 untestable.
- **`ON CONFLICT DO NOTHING` has no conflict target.** It tolerates any unique violation on the table, and today the only one is `(generation_id, type)`. If PE-017 adds another unique index, it must not reuse this writer for purchases.
- **Sums.** Postgres `SUM(bigint)` returns `numeric`, which arrives as a string. Readers (the tests now, PE-016 and PE-017 later) cast to `bigint` in SQL.
- **Concurrency test hygiene.** Its teardown truncates `generations, users … cascade`, which also empties `credit_ledger_entries`, so no change is needed.
