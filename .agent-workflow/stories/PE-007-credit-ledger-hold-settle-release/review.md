# Review — PE-007-credit-ledger-hold-settle-release

## Round 1

**Verdict: REQUEST CHANGES**

Scope reviewed: the 13 `[PE-007]` commits `607b635..43911cb` on `pe-007-credit-ledger-hold-settle-release` (`git log origin/main..HEAD`), against `story.md` and `plan.md`.

Checks run on HEAD (`chain-make.sh chain check`, exit 0):
- Pint passes on 123 files.
- Larastan reports no errors.
- PHPUnit runs 607 tests with 2,725 assertions, all passing.
- ESLint, Prettier and vue-tsc are clean, and Vitest runs 107 tests, all passing.

### Summary

The implementation matches the plan class for class: `CreditLedgerEntryType`, `CreditLedgerEntry`, `ImmutableCreditLedgerEntryException`, both migrations, `HoldGenerationCreditsAction`, `SettleGenerationCreditsAction`, the `CreateGenerationAction` hold, the `TransitionGenerationAction` money steps, and the `GenerationFactory` holds and settlements.

- **Ledger (AC3, AC14, AC19):**
  - `credit_ledger_entries` has a plain `string` type, so there is no native enum, and a signed `bigint` amount. `created_at` is a `timestampTz` and there is no `updated_at`.
  - `unique(generation_id, type)` is in place, and both foreign keys use `restrictOnDelete`, which matches `generations.user_id`.
  - The model refuses `updating` and `deleting`, and the raw row is compared before and after each attempt.
  - The FK test checks SQLSTATE `23503`, and the null-generation rows never collide.
- **Hold (AC1–AC3):**
  - The hold reads the stored `user_estimate_max_nanodollars`, not the estimate object, inside the create transaction.
  - A throwing `CreditLedgerEntry::created` listener leaves no row and no entry.
- **Settle and release (AC4–AC11):**
  - A succeeded snapshot is priced from its own rates before any write (`TransitionGenerationAction.php:191-201`).
  - The release is the `Nanodollars` negation of the stored hold entry. It is owned by the hold's `user_id`, so a settlement cannot move another user's credit.
  - The four settlement rows reproduce every story figure exactly, including AC7's ceil (17,987,517) and AC8's warning context.
  - AC9 changes both the stored max and the pinned rates, and the release is unaffected.
  - Failures, from the provider or local, store 0 / 0 and only release.
- **Exactly once (AC12–AC17):**
  - The PE-006 pair tables now also compare the ledger on every non-applied pair.
  - The `DB::listen` failure injection rolls back the status, costs and partial entries for all four terminal paths.
  - `fillAndInsertOrIgnore` goes through `fillForInsert`, so the enum cast and `created_at` apply. I checked this in the framework source (`Eloquent/Builder.php:564`, `:1519`). The AC15 test proves the transaction is still usable afterwards (`select 1`).
  - The new two-process race asserts exactly {`applied`, `ignored_conflict`}, and a charge only on a succeeded row.
- **Bad data and D-09 (AC18, AC20):**
  - An unpriceable rate is logged with `generation_id`, `provider_id` and `error`, then rethrown before any write.
  - D-09 runs only on `IgnoredConflict` + succeeded + `local_failure_reason` + `output_tokens IS NULL`, and its write is still conditional on the status. A replay changes nothing, which the time-travelled raw-row comparison proves. The user charge stays 0 and the ledger is untouched.
- **Deviation:** AC18's missing-usage case never reaches this story's code, because `GenerationSnapshot::from()` already refuses it. Pinning the parser and handing the generation-id log to the parsing callers is the right call, but see W1: the handoff has not reached PE-008.
- **Style and security:**
  - Every file has `strict_types`, every class is `final`, every method has a return type, and there is no `else`. Types are enums, not magic strings.
  - Billing imports no Generation class.
  - There is no HTTP surface. The cost columns are fillable only so the transition's `fill()` applies the casts, and only that action writes them.
  - The settlement reads the hold by `(generation_id, type)`, which the unique index covers.
  - There is no API contract yet.

### Commit hygiene

- The plan (`607b635`) and the checkboxes and status (`43911cb`) each have their own bookkeeping commit.
- There is one code commit per plan task (`6aa889c`..`8e7ae24`), each with its tests. Tasks 6, 7 and 11 pin behaviour with tests only, as planned.
- The D-25 row in `requirements.md` comes with `90bd4da`, the commit that introduces the `ON CONFLICT DO NOTHING` writes it documents, as the plan specified.
- Every subject uses `[PE-007] Imperative …` and is at most 67 characters. Every commit ends with the trailer.
- I ran the checks on HEAD only. Each intermediate commit is self-contained by inspection.

### Warnings

- [x] **W1. AC18's "logs the generation id" for a succeeded object without `usage` is handed off only inside this story's own artifacts, so it would be lost.**
  - The plan (`plan.md:107`) and the test docblock (`tests/Unit/Domain/Generation/Actions/TransitionGenerationActionExactlyOnceTest.php:223-227`) say PE-008, PE-009 and PE-014 must log `metadata.generation_id` when `GenerationSnapshot::from()` throws.
  - PE-014 already covers it: its AC4 logs any per-row exception with the generation id.
  - PE-009 only parses create responses, which are never `succeeded`.
  - PE-008 does not cover it. Its AC7 (`.agent-workflow/stories/PE-008-verified-idempotent-webhooks/story.md:36`) handles a `generation.*` event *without* `data.object`. Nothing covers a `data.object` that `from()` rejects. Such an event would hit an unspecified path, probably an unlogged 500, and Pixelforge would retry it forever. It would also never log the id that this story's AC18 promises.
  - Fix: carry the obligation into PE-008, as `72ee8a6` did for PE-006's deferred items. Add a Technical Notes bullet to PE-008's `story.md`, for example: "**Unparseable snapshot (PE-007 AC18):** a validly signed `generation.*` event whose `data.object` makes `GenerationSnapshot::from()` throw, e.g. a succeeded object without `usage`, is logged at error level with `data.object.metadata.generation_id` and the event id, and its response is decided explicitly (400 = no retry, or 500 = retry)." Commit it as bookkeeping. Also change the test docblock to name PE-008 and PE-014 only, since PE-009 never parses a succeeded object.

### Suggestions

- [x] **S1. The hold negates with a native `-` while the settlement negates through `Nanodollars`.**
  - Hold: `app/Domain/Billing/Actions/HoldGenerationCreditsAction.php:25` (`-$maxUserPrice->amount`).
  - Settlement: `SettleGenerationCreditsAction.php:37` and `:45` (`Nanodollars::zero()->minus(…)`).
  - The input is a stored non-negative max, so the result is always safe today. Still, `-PHP_INT_MIN` would silently become a float, and the `Nanodollars` docblock promises that never happens.
  - Fix: use `Nanodollars::zero()->minus($maxUserPrice)->amount` for one consistent, overflow-checked negation.
- [x] **S2. The Lighthouse request is spelled out twice.**
  - `givenTheLighthouseGeneration()` (`tests/Concerns/InteractsWithGenerations.php:45-53`) and the `settlements()` data provider (`tests/Unit/Domain/Generation/Actions/TransitionGenerationActionSettlementTest.php:73`) each build it, with the same reference URL.
  - Fix: add a `protected static function lighthouseRequest(): NewGenerationData` to the trait. The static data provider and `givenTheLighthouseGeneration()` can then both use it.
