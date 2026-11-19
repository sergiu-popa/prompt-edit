# QA — PE-007-credit-ledger-hold-settle-release

**Verdict: QA PASSED**

Every AC passes. The one failure from the first run (AC18, logging the generation id for a succeeded object without `usage`) is fixed in `cfa63c4` and re-verified below. Status moves to `qa-passed`.

Prerequisites checked: story status `qa-ready`, review verdict `APPROVE` (round 2). Branch `pe-007-credit-ledger-hold-settle-release` at `b3875fc`, worktree clean at the start of this run. This run supersedes the first run, which ended QA FAILED.

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Full check | `chain-make.sh chain check` | Exit 0. Pint: PASS, 124 files. Larastan: no errors. PHPUnit 610 passed (2,750 assertions), none skipped, risky or incomplete. ESLint, Prettier and vue-tsc clean. Vitest 107 passed (9 files). |

Log: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-007/check-run2.log`.

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 Lighthouse hold −457,150,000 from the stored max | `CreateGenerationActionTest::test_the_lighthouse_generation_holds_its_stored_max_user_estimate` | Pass |
| 2 docs maximum hold −467,343,750 | `CreateGenerationActionTest::test_the_docs_maximum_case_holds_its_max_user_estimate` | Pass |
| 3 row and hold commit together; no hold without its generation | `CreateGenerationActionTest::test_a_failing_hold_write_leaves_no_row_and_no_entry`; `CreditLedgerEntryTest::test_an_entry_for_an_unknown_generation_is_refused` (SQLSTATE 23503) | Pass |
| 4 costs null and no ledger write while non-terminal | `TransitionGenerationActionSettlementTest::test_queued_and_processing_snapshots_leave_the_costs_unset_and_only_the_hold` | Pass |
| 5 Lighthouse settle: 305,510,000 / 381,887,500; release +457,150,000, charge −381,887,500, net −381,887,500 | `TransitionGenerationActionSettlementTest::test_a_succeeded_snapshot_charges_its_actual_and_releases_the_hold` (AC5 row) | Pass |
| 6 docs maximum charge = hold 467,343,750; net −467,343,750; no warning | same test, AC6 row (`warned` false) | Pass |
| 7 snapshot's own rates; ceil 17,987,517; provider 14,390,013 | same test, AC7 row | Pass |
| 8 actual above hold: charge 31,987,500; one warning with id, hold, charge | same test, AC8 row (`warned` true, context asserted) | Pass |
| 9 release negates the stored hold, not a recomputed estimate | `TransitionGenerationActionSettlementTest::test_the_release_negates_the_hold_entry_and_never_a_recomputed_estimate` | Pass |
| 10 provider failed: 0 / 0, release only, net 0 | `TransitionGenerationActionSettlementTest::test_a_failed_snapshot_is_free_and_releases_the_hold` (both codes) | Pass |
| 11 local failure: same outcome as AC10 | `TransitionGenerationActionSettlementTest::test_a_local_failure_is_free_and_releases_the_hold` | Pass |
| 12 succeeded twice / failed twice: one release, one charge, nothing thrown | `TransitionGenerationActionExactlyOnceTest::test_a_succeeded_snapshot_applied_twice_settles_once`; `test_a_failed_snapshot_applied_twice_releases_once` | Pass |
| 13 late processing after succeeded; failed after succeeded; succeeded after provider failed: nothing written | `TransitionGenerationActionExactlyOnceTest::test_a_later_snapshot_never_changes_a_terminal_generations_money` (3 rows) | Pass |
| 14 DB rejects a second hold/release/charge per generation; null generation never collides | `CreditLedgerEntryTest::test_the_database_refuses_a_second_entry_of_one_type_for_a_generation` (3 types); `test_entries_without_a_generation_never_collide` | Pass |
| 15 bypassed guard: duplicates ignored, transaction usable, one of each, logged | `TransitionGenerationActionExactlyOnceTest::test_duplicates_left_by_a_bypassed_status_guard_are_ignored_and_logged` | Pass |
| 16 two connections: one release and one charge; succeeded vs failed gives one outcome, failed never charged | `TransitionGenerationActionConcurrencyTest::test_two_simultaneous_appliers_of_one_snapshot_write_the_row_once`; `test_a_succeeded_and_a_failed_snapshot_at_once_settle_exactly_one_outcome` (real child processes) | Pass |
| 17 failing ledger insert rolls back status, costs and entries, for all four terminal paths | `TransitionGenerationActionExactlyOnceTest::test_a_failing_settlement_write_rolls_the_whole_transition_back` (4 rows) | Pass |
| 18 bad rate: throws, logs generation id, writes nothing | `TransitionGenerationActionExactlyOnceTest::test_a_rate_that_is_not_a_whole_nanodollar_is_refused_logged_and_writes_nothing` | Pass |
| 18 missing usage: throws, logs generation id, writes nothing | `TransitionGenerationActionExactlyOnceTest::test_a_succeeded_object_without_usage_is_refused_logged_and_writes_nothing` (log context `generation_id`, `provider_id`, `error` asserted exactly); `GenerationSnapshotTest::test_an_incomplete_succeeded_snapshot_without_our_generation_id_logs_a_null_id`; `test_a_valid_snapshot_logs_nothing` | Pass (fixed in `cfa63c4`, see *First run*) |
| 19 update and delete throw, row unchanged; `created_at` only | `CreditLedgerEntryTest::test_updating_an_entry_throws_and_leaves_the_row_unchanged`; `test_saving_a_changed_entry_throws_and_leaves_the_row_unchanged`; `test_deleting_an_entry_throws_and_leaves_the_row_unchanged`; `test_an_entry_reads_back_with_its_casts_and_a_creation_time` | Pass |
| 20 D-09: stays failed; provider cost 305,510,000 plus usage, rates and `completed_at` recorded; charge 0; net 0; anomaly logged; replay changes nothing | `TransitionGenerationActionConflictsTest::test_the_lighthouse_succeeding_after_a_local_failure_records_its_cost_but_charges_nothing` | Pass |

## Money check

I recomputed each figure from the story's formulas, separately from the code. All match the assertions.

- Provider max, Lighthouse: 14×5,000 + 765×10,000 + 6,450×40,000 + 8,000×12,500 = 365,720,000. ×1.25 = 457,150,000.
- Provider max, docs maximum: 373,875,000. ×1.25 = 467,343,750.
- AC5 provider: 60,000 + 7,650,000 + 258,000,000 + 39,800,000 = 305,510,000. ×1.25 = 381,887,500 (exact).
- AC6 provider: 575,000 + 15,300,000 + 258,000,000 + 100,000,000 = 373,875,000. Charge 467,343,750, equal to the hold.
- AC7 provider: 65,013 + 11,200,000 + 3,125,000 = 14,390,013. ×1.25 = 17,987,516.25, ceil 17,987,517.
- AC7 hold: 75,000 + 11,200,000 + 6,250,000 = 17,525,000. ×1.25 = 21,906,250.
- AC8 provider: 65,000 + 22,400,000 + 3,125,000 = 25,590,000. ×1.25 = 31,987,500.
- Factory: provider 87,450,000, charge 109,312,500.

Each charge is recorded once. Replays and concurrent appliers are covered by the exactly-once and concurrency tests above, which all pass in this run.

## Edge cases

No scratch probes were added. The edge cases are the named tests above: replay, concurrent applier, succeeded versus failed race, bypassed guard, rounding boundary (AC7's `5.001`), exactly-at-hold boundary (AC6), above-hold (AC8), non-whole rate (AC18), missing usage (AC18), and the local-failure-then-provider-success path (AC20). Every case passes in the full run.

Not probed: a succeeded snapshot with all-zero usage. The story does not specify it, and it is not in scope.

## Regressions

The full PHPUnit, Vitest, Larastan, Pint, ESLint, Prettier and vue-tsc suites pass. No pre-existing test was removed or skipped.

## First run

The first QA run (commit `9d8cdcc`) ended QA FAILED on one item: AC18's "logs the generation id" for a succeeded object without `usage`. The parser did not log it, and the test asserted only the throw. The fix is in `cfa63c4`: `GenerationSnapshot::from()` logs at error level with `generation_id` (from `metadata.generation_id`, null when absent), `provider_id` and the error, before it throws. This run re-verified it in code and tests (see the AC 18 row). The story's AC text was not changed.

## Visual verification

No UI in this story (`story.md`: "No UI"). It is backend only. No browser pass, screenshots, horizontal-scroll check, console check or E2E run applies in this worktree, and none was attempted. The orchestrator re-runs the E2E suite on the main stack.

No fixture rows were written to any shared database. All tests ran in the isolated chain test database, under `RefreshDatabase` or truncation. The concurrency test truncates the chain test database in teardown, as planned.

## Notes (non-blocking)

- The PR is about 1,600 changed lines, over the ~400 budget. Review and plan accepted one PR per story, with a cut point after task 7 in case the user asks for a split.
- The AC9 and AC18 tests override `config('pricing')` and raw-update the stored max. They restore the state by `RefreshDatabase`, not by explicit cleanup, which is the project's normal pattern.
