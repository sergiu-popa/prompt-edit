# Audit — PE-007-credit-ledger-hold-settle-release

**Verdict: PASS**

Run on branch `pe-007-credit-ledger-hold-settle-release` from `8372bb8` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (APPROVE, round 2) and `qa.md` (QA PASSED) present.

## Scope

I checked the whole `[PE-007]` diff (`git diff 72ee8a6..HEAD`) against `story.md` AC1–20, `plan.md`, both review rounds, both QA runs and `.agent-workflow/CLAUDE.md`. That covers:
- both migrations, `CreditLedgerEntry`, `CreditLedgerEntryType` and `ImmutableCreditLedgerEntryException`;
- `HoldGenerationCreditsAction` and `SettleGenerationCreditsAction`;
- the money steps in `CreateGenerationAction` and `TransitionGenerationAction`;
- the `Generation` cost columns, the `GenerationSnapshot` log, and the `GenerationFactory` holds and settlements;
- the D-26 row, the PE-008 hand-off, the four test concerns and every test file.

Where the auditor checklist mentions Livewire, the Inertia/Vue rules apply instead. This story has no HTTP surface, page or prop, so the controller and prop rules have nothing to check. PR size is not a finding (`.agent-workflow/CLAUDE.md` § Git hygiene).

## What was verified

- **Append-only.**
  - The model refuses `updating` and `deleting` (`CreditLedgerEntry.php:37-46`). `UPDATED_AT = null`, and there is no `updated_at` column.
  - Nothing in `app/` updates or deletes ledger rows. The only writers are one `create()` (hold) and two `fillAndInsertOrIgnore()` (release, charge).
  - Before this audit, the database itself accepted a raw `UPDATE` or `DELETE`. That is issue #1, now fixed with a trigger.
- **A second charge is impossible.**
  - `unique(generation_id, type)` covers hold, release and charge per generation. The DB raises 23505 on a direct second insert (AC14 test, all three types).
  - Release and charge use `ON CONFLICT DO NOTHING`, so the forward-only guard is the first layer and the index is the second. A bypassed guard leaves one of each, and the transaction stays usable (AC15).
  - The transition reads the row under `lockForUpdate` and writes with an UPDATE conditional on the status it read, so settlement runs at most once per transition.
- **Hold = the stored max.** `CreateGenerationAction.php:59-63` holds `-user_estimate_max_nanodollars` from the created row, inside the create transaction. The foreign key and the shared transaction make sure neither exists without the other (AC3).
- **Settle = the actual plus the release, with the status change.**
  - `applySuccess` prices from the snapshot's own `usage` and `rates` (`TokenRates::from($snapshot->rates)`) before anything is written. It never reads `config('pricing')`.
  - The user charge is `ceil(provider × 125 / 100)` through `BigInteger` with `RoundingMode::Ceiling`.
  - The status, the costs and the ledger entries are written in the one `DB::transaction` of `execute()`. AC17's failure injection rolls all of them back on every terminal path.
  - The release is the `Nanodollars` negation of the stored hold entry, owned by the hold's user, never a recomputed estimate (AC9).
- **Failed = free.** A provider failure and a local failure both store 0 / 0 and write only the release (AC10, AC11). A succeeded and a failed snapshot racing on two connections never produce a charge on a failed row (AC16).
- **D-09.** A succeeded snapshot over a locally failed row records the provider cost, usage, rates and `completed_at`. It leaves the status `failed`, the user charge 0 and the ledger untouched. `output_tokens IS NULL` under the row lock makes it write once. A provider-failed row that later reports success is a provider contradiction, not D-09: it is logged as a conflict and left alone, as AC13 requires.
- **No float, overflow guarded.**
  - No `float`, `round`, `floor`, `ceil` or `/` touches money in the diff.
  - Every negation and sum goes through `Nanodollars` / `BigInteger`, which throws `NanodollarOverflowException` outside the 64-bit range. The hold's `PHP_INT_MIN` test pins it.
  - Rates are refused unless they are a whole n$ per token. The rate pattern has no sign and `TokenUsage` refuses negative counts, so a provider cost can never be negative.
  - Every money column is `bigint`. Test sums are cast to `bigint` in SQL, never read as `numeric` or float.
- **Concurrency.** Two child processes apply the same succeeded snapshot and write exactly one release and one charge. A succeeded and a failed child give exactly {`applied`, `ignored_conflict`}. Both wait on the lock before it is released (`pg_stat_activity`).

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | The ledger was append-only only at the model. A raw `DB::table('credit_ledger_entries')->update()` or `->delete()` changed a money entry without any error. The code relied on the convention that nothing in the app does that. D-08 asks for "never recorded twice" to be enforced, not conventional, and an edited or deleted hold, release or charge would silently corrupt every balance PE-017 derives. | `database/migrations/2026_11_19_163549_create_credit_ledger_entries_table.php:16` | Warning | Fixed | A new migration adds a `BEFORE UPDATE OR DELETE … FOR EACH ROW` trigger that raises SQLSTATE 23001 with the entry id. It uses `CREATE OR REPLACE FUNCTION`, because `migrate:fresh` drops tables but keeps functions. TRUNCATE fires no row trigger, so test truncation is unaffected. The FKs are `restrictOnDelete`, so no cascade can reach it. A new two-row test (raw update, raw delete) proves the refusal and an unchanged row. It failed with the migration removed and passes with it. Rollback and re-migrate were checked (`4ad5bc1`). |
| 2 | Two ledger guards stay open, and they were recorded only in this story's `plan.md` Risks. First, a `hold`, `release` or `charge` with a null `generation_id` is not limited by the unique index. Second, D-26's `ON CONFLICT DO NOTHING` has no conflict target, so it would also swallow a future unique index's violation. PE-017 is the story that makes both matter, and its story did not mention either. | `app/Domain/Billing/Actions/SettleGenerationCreditsAction.php:76` | Suggestion | Deferred | Carried into PE-017's Technical Notes (`61face3`): add a CHECK that those three types carry a generation once `grant` and `purchase` exist, and keep purchases and grants off the target-less writer if another unique index is added. A CHECK today would make AC14's null-generation half untestable, because there is no generation-less type yet. |
| 3 | There is no database CHECK on the sign of `amount_nanodollars` per type (hold and charge ≤ 0, release ≥ 0). | `database/migrations/2026_11_19_163549_create_credit_ledger_entries_table.php:21` | Suggestion | Skipped | Only two actions write entries, each through an overflow-checked `Nanodollars` negation of a non-negative value, and every story amount is pinned with `assertSame`. The trigger in #1 already stops later edits. Nothing in the story or plan asks for it. |
| 4 | `provider_cost_nanodollars` and `user_charge_nanodollars` are in `Generation`'s `#[Fillable]`. | `app/Domain/Generation/Models/Generation.php:77-78` | Suggestion | Skipped | The transition's `fill()` needs them there so the casts apply. There is no HTTP surface, and PE-011 creates rows only through `CreateGenerationAction`'s explicit array, never from request input. |
| 5 | D-09's write records the cost, usage, rates and `completed_at` but not the snapshot's provider `id`. | `app/Domain/Generation/Actions/TransitionGenerationAction.php:242-250` | Suggestion | Skipped | AC20 names exactly those four, and `/v1/usage` bills by UTC completion day (docs#usage), which `completed_at` covers. The provider id is in the conflict log. PE-018 can raise it if per-id reconciliation turns out to need it. |
| 6 | `TransitionGenerationAction::execute()`'s `@throws` omits `InvalidGenerationSnapshotException`, which `price()` can throw. | `app/Domain/Generation/Actions/TransitionGenerationAction.php:57-60` | Suggestion | Skipped | That throw only narrows the types for Larastan. `GenerationSnapshot::from()` already refuses a succeeded object without `usage` or `rates`, so it cannot happen at runtime (`price()`'s docblock says so). |

No Critical issues were found.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `chain-make.sh chain check` | Exit 0. Pint passes on 125 files and Larastan reports no errors. PHPUnit: 612 passed (2,757 assertions), up from 610 by the two new rows, with the concurrency tests included. ESLint, Prettier and vue-tsc are clean, and Vitest runs 107 passed in 9 files. |
| New rows, failing first | `php artisan test --filter=test_the_database_refuses_a_raw_write` (migration removed, then restored) | Without the trigger: 2 failed. With it: 2 passed. |

Log: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-007/audit-check1.log` (after `4ad5bc1`; `61face3` changes only a story file).

This is a backend-only story, so the E2E suite was not run here. The orchestrator re-runs it on the main stack.
