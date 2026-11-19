# Review — PE-006-generation-record-and-transitions

## Round 1

**Verdict: REQUEST CHANGES**

Scope reviewed: the 15 `[PE-006]` commits `9f79c6e..8428d40` on `pe-006-generation-record-and-transitions` (`git diff origin/main...HEAD`), against `story.md` and `plan.md`.

Checks run on HEAD (`chain-make.sh chain check`, exit 0):
- Pint passes on 108 files.
- Larastan reports no errors.
- PHPUnit runs 545 tests with 2,101 assertions, all passing.
- ESLint, Prettier and vue-tsc are clean, and Vitest runs 107 tests, all passing.

### Summary

The implementation matches the plan class for class: `GenerationStatus`, `GenerationTransitionOutcome`, `LocalFailureReason`, the `generations` migration, `Generation`, `GenerationFactory`, `NewGenerationData`, `CreateGenerationAction`, `GenerationSnapshot`, `InvalidGenerationSnapshotException`, `LocalGenerationFailureData`, `TransitionGenerationAction` and `ConcurrentGenerationTransitionException`.

- **Record (AC1–AC5, AC15–AC16):**
  - The ULID is a `char(26)` key, no column draws from a sequence, and `user_id` is the only foreign key and the only integer key.
  - `provider_id` is nullable and unique.
  - The worked example stores 95,237,500 / 109,312,500 / 123,387,500. A throwing `created` listener leaves no row.
  - The prompt and reference URLs read back byte-identical.
  - `#[DateFormat('Y-m-d H:i:sP')]` with `timestampTz` keeps `2026-11-18T23:59:59Z` exact under a `Europe/Bucharest` PHP and session timezone.
  - The factory states build on each other and satisfy AC16. The succeeded state's usage prices to exactly the definition's typical provider cost (87,450,000 n$).
- **Parser (AC6–AC7):**
  - Both docs fixtures and the envelope's `data.object` parse.
  - Every AC7 rejection and every boundary row in the plan's table has a test row naming the field path.
  - Timestamps are checked twice: the regex and then `createFromFormat` warnings, so Feb 30 is rejected rather than rolled over to March.
  - `pending` is refused as a status that is ours, not Pixelforge's.
  - Usage goes through PE-001's `TokenUsage::from()`, and its exception is kept as `previous`.
- **Transition (AC8–AC14):**
  - The classifier checks identity first, then terminal rows, then rank. It reproduces the AC8 table exactly, and the 25-pair data provider checks the reported outcome and, for every non-applied pair, the untouched raw row (with time travel, so a stray `updated_at` would show).
  - Every conflict logs once at error level with the six context keys. Duplicates, stale snapshots and terminal snapshots are not logged.
  - The write fills the locked model so the casts and the offset date format apply, then issues an UPDATE conditional on the key and the status read under the lock. The simulated missed lock proves it throws and rolls back.
  - The two-process test holds the row lock, confirms through `pg_stat_activity` that both children are waiting on it, and then commits. It sees exactly one `applied` with one UPDATE and one `ignored_duplicate` with none.
- **Deviations from the plan:** the child takes the object as argv instead of stdin, and the poll calls `pg_stat_clear_snapshot()`. Both are justified in the Engineer notes, and I agree with them. Leaving `completed_at` null on a local failure is also sensible.
- **Style:** every file has `declare(strict_types=1)`, every class is `final` and every DTO `readonly`. Every method has a return type, there is no `else`, and statuses and outcomes are enums rather than magic strings.
- **Security and performance:** there is no HTTP surface. `user_id` is fillable, but only the create action writes it, from a `User` model. Every query is a single-row lookup by primary key. The history index is deferred to PE-011, as the plan says. No API contract is involved yet.

### Commit hygiene

- One bookkeeping commit each for the prompt log (`9f79c6e`), the plan (`54bfae3`) and the checkboxes and status (`8428d40`).
- One code commit per plan task (`d3ded02`..`2d5ba11`), each with its tests.
- Subjects use the `[PE-006] Imperative …` form, none is longer than 72 characters, and every commit ends with the trailer.
- The D-24 row in `requirements.md` comes with `6b68024`, the commit that introduces the action it documents, as the plan specified.
- Prompt 10 is logged verbatim.
- I ran the checks on HEAD only. Each intermediate commit is self-contained by inspection.

### Warnings

- [ ] **The same transition-test helpers are copied into three or more files** (guidelines §6: extract shared setup into a `Tests\Concerns` trait when it repeats in 3+ files):
  - `rawRow()`: `tests/Unit/Domain/Generation/Actions/TransitionGenerationActionTest.php:336`, `TransitionGenerationActionConflictsTest.php:224` and `TransitionGenerationActionSequencesTest.php:141`.
  - `transition()`: `TransitionGenerationActionTest.php:328`, `TransitionGenerationActionConflictsTest.php:216` and `TransitionGenerationActionSequencesTest.php:133`.
  - `reloaded()`: `TransitionGenerationActionTest.php:341`, `CreateGenerationActionTest.php:169` and `tests/Unit/Database/Factories/GenerationFactoryTest.php:99`.
  - `givenAGeneration(GenerationStatus)` is an identical `match`: `TransitionGenerationActionTest.php:315` and `TransitionGenerationActionConflictsTest.php:203`.
  - `assertInstant()`: `TransitionGenerationActionTest.php:346` and `tests/Unit/Support/Pixelforge/DataTransferObjects/GenerationSnapshotTest.php:374`.

  PE-007 (settle, release and D-09 on these exact outcomes), PE-008 and PE-014 all need "a row in status X", "apply this change" and "the raw row is untouched", so a fourth and fifth copy would follow.
  - Fix: add `Tests\Concerns\InteractsWithGenerations` with domain-named helpers, for example `givenAGenerationIn(GenerationStatus)`, `transitionGeneration(Generation, GenerationSnapshot|LocalGenerationFailureData)`, `rawGenerationRow(Generation)`, `reloadedGeneration(Generation)` and `assertSameInstant(string, mixed)`. Use it in the five files and delete the private copies. Keep `InteractsWithGenerationSnapshots` for Pixelforge objects only.
  - Commit: `[PE-006] Share generation test helpers in a concern`.
- [x] **The PR is far over the project's size rule.** `.agent-workflow/CLAUDE.md` § Git hygiene sets about 400 changed lines and says to split a larger story. The story's Technical Notes also said to split it into (a) AC1–AC7 and AC15–AC16 and (b) AC8–AC14 *before planning*. This branch has about 3,180 changed lines:
  - `app/` 934, `database/` 173;
  - `tests/Unit` 1,666, `tests/Concerns` 94, `tests/fixtures` 89 and `tests/scripts` 49;
  - `.agent-workflow` 172.

  `plan.md` § Risks says "The user accepts one PR of 12 self-contained commits", but nothing in `.agent-workflow/prompts.md` records that the user agreed. The natural cut is after `d9498b2`: PR A holds the record, the create action and the parser (tasks 1–6), and PR B holds the transition action (tasks 7–12, about 1,000 lines).

  — acknowledged: this pipeline cannot fix it, because splitting now means rewriting history, which this step may not do. Each commit can be reviewed on its own. The largest is `f261613` at 699 lines, of which about 90 are verbatim docs fixtures and most of the rest are data-provider rows. **The user must choose before the PR is opened:** one PR of 15 commits, or two sequential PRs split at `d9498b2`. The PR description must state the size and the choice, and must not claim the user's acceptance until the user has given it.

### Suggestions

- [ ] `app/Support/Pixelforge/DataTransferObjects/GenerationSnapshot.php:269-304`: `rates()` re-implements the key and type rules of PE-001's `TokenRates::from()` (`app/Domain/Billing/ValueObjects/TokenRates.php:42-100`), but leaves out its `RATE_PATTERN`. As a result `"abc"`, `""` or `"5.00\n"` parses and is written onto a succeeded row. Once PE-007 settles inside this transaction, `TokenRates::from()` will throw there, and that webhook can then never apply.
  - Fix: at the boundary, reject a rate that does not match `/^[0-9]+(?:\.[0-9]+)?\z/` (ideally a shared constant or a `TokenRates` validator), keeping the strings verbatim. The plan's decision to leave the whole-n$ check (`"5.0001"`) to PE-007 can stay as it is.
  - Add one test row each for `"abc"` and `"5.00\n"`.
- [ ] `database/factories/GenerationFactory.php:94`: there is no state for a locally failed row (`failed`, `provider_id` null, `local_failure_reason` set). AC16's "failed has an error or a local reason" therefore covers only the first branch.
  - PE-007's D-09 path (an `IgnoredConflict` over a locally failed row) and PE-009 both need one. `TransitionGenerationActionConflictsTest` currently gets the `failed row, succeeded snapshot` pair only from a Pixelforge-failed row.
  - Fix, if wanted: add `failedLocally()` building on the definition (pending, no provider id) with `ContentPolicyViolation`, and add one factory test row.
