# Audit — PE-006-generation-record-and-transitions

**Verdict: PASS**

Run on branch `pe-006-generation-record-and-transitions` from `bb0a24f` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (APPROVE, round 2) and `qa.md` (QA PASSED) present.

## Scope

I checked the whole `[PE-006]` diff (`git diff main...HEAD`) against `story.md` AC1–16, `plan.md`, both review rounds, the QA findings and `.agent-workflow/CLAUDE.md`. That covers:
- the `generations` migration, `Generation` and `GenerationFactory`;
- the three Generation enums, both DTOs and both exceptions;
- `CreateGenerationAction` and `TransitionGenerationAction`;
- `GenerationSnapshot` and its exception, and the `TokenRates::RATE_PATTERN` visibility change;
- the D-24 row, the fixtures, the child script, the three test concerns and every test file.

Where the auditor checklist mentions Livewire, the Inertia/Vue rules apply instead. This story has no HTTP surface, page or prop, so the controller and prop rules have nothing to check. PR size is not a finding (`.agent-workflow/CLAUDE.md` § Git hygiene). D-24 stays as it is. PE-005 also claims that number, and the orchestrator renumbers whichever story merges second.

## What was verified

- **Forward-only state machine.**
  - `classifySnapshot` checks identity first, then a terminal row, then rank. A terminal row can only report `IgnoredDuplicate`, `IgnoredConflict` or `IgnoredTerminal`, so no snapshot path writes it.
  - `classifyLocalFailure` applies only to `Pending`.
  - Nothing reaches `Applied` without a strictly higher rank on a non-terminal row. Applied is therefore never a no-op, and the UPDATE always has a status change in it.
  - The 25-pair data provider compares the raw row before and after under time travel. It reproduces the AC8 table exactly.
- **Idempotent replays.** An equal rank is `IgnoredDuplicate` and issues no UPDATE. The five-replay sequence gives one `Applied` and four duplicates, and the raw row is unchanged after the first.
- **Row lock and conditional update.**
  - `lockForUpdate()` re-reads the row by key inside `DB::transaction`, which becomes a savepoint inside a caller's transaction (`TransitionGenerationAction.php:33`).
  - The write is `UPDATE … WHERE id = ? AND status = <status read under the lock>` (`:145`). Anything other than one affected row throws and rolls back, which the simulated missed lock proves.
  - Values are filled onto the model and written from `getDirty()`, so the casts and the `+00:00` date format apply. The `Europe/Bucharest` session test pins that.
  - Every key the action fills is in `#[Fillable]`. The app does not enable `preventSilentlyDiscardingAttributes`, so I checked this by hand: no attribute is dropped.
  - The two-process test confirms through `pg_stat_activity` that both children wait on the lock before it is released, and shows one UPDATE and one duplicate.
- **Conflict cases.**
  - A missing or foreign `metadata.generation_id` is refused before any rank check, even on a terminal or pending row. So is an `id` that differs from the stored provider id.
  - The opposite terminal status is `IgnoredConflict`. That includes a succeeded snapshot over a locally failed row, the D-09 hook.
  - A local failure on a queued or processing row is refused.
  - Each conflict logs once at error level with all six context keys. Duplicate, stale and terminal outcomes do not log.
- **Rates as received strings.**
  - `rates()` requires exactly the `TokenType` keys and `TokenRates::RATE_PATTERN`. It keeps each string verbatim in input order (`"5.0001"` survives).
  - The column is `json`, not `jsonb`, so key order and text read back with `assertSame`.
  - Nothing converts the strings to numbers before PE-007's settlement.
- **Estimate recomputed server-side.** `NewGenerationData` has no price field. `CreateGenerationAction` always calls `EstimateGenerationCostAction` and stores its `userPrice` min, typical and max. The AC1 worked example reads back 95,237,500 / 109,312,500 / 123,387,500.
- **Migration.**
  - `id` is `char(26)` and the primary key, so it is unique and indexed. No column draws from a sequence, and `user_id` is the only integer key and foreign key (`restrictOnDelete`).
  - `provider_id` is nullable and unique, which gives it a unique index. NULLs are distinct in Postgres, so any number of pending rows can exist.
  - Every money and token column is `bigint`. `rates` and `reference_images` are `json`, and every instant is `timestamptz`. There is no float, double or decimal column.
- **Inputs Postgres would silently alter.** A probe in the chain container showed that `select ?::text` bound to `"gen_a\0xyz"` returns `gen_a`, so pdo_pgsql cuts text at a NUL. It also showed that `json_decode` turns `\u0000` into a NUL byte.
  - The create action already refused NUL in the prompt and the reference URLs (QA round 1).
  - The snapshot parser did not refuse it, which is issue #1 below.
  - Invalid UTF-8 is not silent: Postgres raises an error and the transaction rolls back (issue #4).
- **Timestamps.** The regex check and then the `createFromFormat` warnings reject Feb 30, `24:00:00`, `23:59:60` and month 13. A probe showed that offsets were not range-checked, which is issue #2 below.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | The snapshot parser accepted a NUL byte (JSON `\u0000`) in `id`, `error.code`, `error.message` and `image_url`, and pdo_pgsql silently cuts each of them at it when the transition writes them. A cut provider id is the worst case. The stored `gen_a` never equals the snapshot's `gen_a\0xyz`, so every later webhook or poll of that generation becomes a `RefusedConflict`. The row then stays queued or processing for good, and PE-007's hold would never settle. | `app/Support/Pixelforge/DataTransferObjects/GenerationSnapshot.php:122`, `:140`, `:256` | Warning | Fixed | `storable()` (`:162`) refuses a NUL with `InvalidGenerationSnapshotException::nulByte()`, naming the field. The other rejections behave the same way. It covers every string the action stores. `metadata.generation_id` is only compared, never stored, and a cut value is already refused as a conflict. Four new `malformedShapes` rows (`81f30d7`). |
| 2 | Timestamp offsets were not range-checked. PHP's `createFromFormat` rolls `+14:60` over to `+15:00` and reads `+99:99` as `+100:39`, both without a warning, so the parser accepted an instant shifted by a nonsense offset. The parser promises to reject that kind of rollover. | `app/Support/Pixelforge/DataTransferObjects/GenerationSnapshot.php:29` | Suggestion | Fixed | The pattern now allows offsets from `00` to `14` hours and `00` to `59` minutes. There are two new rows, `+14:60` and `+99:00` (`dacc1a6`). Pixelforge sends `Z`, so no real input changes. |
| 3 | `LocalGenerationFailureData`'s `errorCode`, `errorMessage` and `errorParam` reach `varchar`/`text` columns unchecked, so a NUL there would also be cut. | `app/Domain/Generation/DataTransferObjects/LocalGenerationFailureData.php:18` | Suggestion | Deferred | Nothing builds this DTO from wire data yet. Its source is PE-009's error-envelope parser (PE-009 AC7). That parser must refuse a NUL in `code`, `message` and `param`, as `GenerationSnapshot::storable()` does, before it builds the failure. |
| 4 | Invalid UTF-8 in a request or snapshot string is not refused with a typed exception. | `app/Domain/Generation/Actions/CreateGenerationAction.php:61`, `app/Support/Pixelforge/DataTransferObjects/GenerationSnapshot.php:162` | Suggestion | Skipped | It is not silent: Postgres raises `22021`, the transaction rolls back and no row is written (QA probe `"a\xFFb"`). Every webhook or API body reaches the parser through `json_decode`, which never yields invalid UTF-8. PE-011's validation gives users the field error. |
| 5 | `user_id` has no index. Postgres does not index a foreign key, so `restrictOnDelete`'s check and any per-user query scan the table. | `database/migrations/2026_11_19_151446_create_generations_table.php:18` | Suggestion | Deferred | `story.md` § Out of Scope gives "the History UI and its index" to PE-011, whose owner-scoped, paginated query defines the right composite (`user_id`, `created_at`). PE-011's story does not name the index yet, so its plan must add it. No query reads by owner in this story. |
| 6 | `error_code` and `error_param` are `varchar(255)` and hold provider strings. | `database/migrations/2026_11_19_151446_create_generations_table.php:38`, `:40` | Suggestion | Skipped | An over-long value raises an error and rolls back, so nothing is silently cut. Pixelforge's codes and params are short identifiers (`content_policy_violation`, `prompt`). |
| 7 | `status` has no database CHECK constraint. | `database/migrations/2026_11_19_151446_create_generations_table.php:19` | Suggestion | Skipped | Only the two actions write the column, both through the enum cast. An unknown value would also fail loudly on read through the same cast. A CHECK would have to be migrated each time a status is added, and nothing in the plan asks for it. |

No Critical issues were found.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `chain-make.sh chain check` | Exit 0. Pint passes on 111 files and Larastan reports no errors. PHPUnit: 565 passed (2,144 assertions), up from 559 by the six new rows, with the concurrency test included. ESLint, Prettier and vue-tsc are clean, and Vitest runs 107 passed in 9 files. |
| New rows, failing first | `chain-make.sh chain test test_a_malformed_object_is_rejected_naming_the_field` | Before each fix: 4 failed and then 2 failed. After: all passed. |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-006/audit-check-1.log` (after `81f30d7`), `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-006/audit-check-2.log` (after `dacc1a6`).

This is a backend-only story, so the E2E suite was not run here. The orchestrator re-runs it on the main stack.
