# QA — PE-006-generation-record-and-transitions

**Verdict: QA FAILED**

Run on branch `pe-006-generation-record-and-transitions` at `557746e`. Prerequisites checked: story status `qa-ready`, review verdict `APPROVE` (round 2).

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Full check | `chain-make.sh chain check` | Exit 0. Pint and Larastan clean. PHPUnit 552 passed (2,118 assertions), none skipped or risky. ESLint, Prettier and vue-tsc clean. Vitest 107 passed. |

Log: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-006/check.log`.

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 pending row, worked example 95,237,500 / 109,312,500 / 123,387,500 | `CreateGenerationActionTest::test_it_writes_a_pending_row_with_the_server_side_estimate` | Pass |
| 2 creation in one transaction, no row on failure | `CreateGenerationActionTest::test_a_failing_write_leaves_no_row` | Pass |
| 3 request fields read back byte-identical | `test_the_prompt_reads_back_byte_identical`, `test_the_reference_images_read_back_in_order`; NUL byte fails (Failure 1) | **Fail** |
| 4 ULID is the only external key, `user_id` the only integer key | `GenerationTest::test_the_ulid_is_the_key_and_no_column_draws_from_a_sequence`, `test_the_owner_is_the_only_foreign_key`, `test_the_model_uses_a_lowercase_ulid_string_key` | Pass |
| 5 unique nullable provider id | `GenerationTest::test_any_number_of_rows_may_have_no_provider_id_but_a_provider_id_is_unique` | Pass |
| 6 snapshot parses from object and webhook `data.object` | `GenerationSnapshotTest::test_the_docs_create_response_parses`, `test_the_docs_succeeded_example_parses`, `test_metadata_generation_id_is_read...`, `test_unknown_top_level_fields_are_ignored` | Pass |
| 7 typed exceptions for each rejection; failed with null error parses | `test_a_malformed_object_is_rejected_naming_the_field`, `test_a_succeeded_snapshot_without_its_result_is_rejected`, `test_a_usage_that_drops_or_misstates...`, `test_rates_that_drop_or_add...`, `test_a_rate_that_is_not_a_decimal_string...`, `test_a_failed_snapshot_without_an_error_parses...` | Pass |
| 8 forward-only table, every pair | `TransitionGenerationActionTest::test_each_snapshot_pair_reports_its_outcome_and_only_an_applied_one_writes` (data provider), `test_a_local_failure_reports_its_outcome...` | Pass |
| 9 applied records fields; ignored and conflicting write nothing | `test_an_applied_queued/processing/succeeded/failed_snapshot_records...`, `test_an_applied_local_failure_records...`; raw row compared under time travel in the table test | Pass |
| 10 webhook before create response | `TransitionGenerationActionSequencesTest::test_a_succeeded_webhook_before_the_create_response_wins...`, `test_a_processing_webhook_before_the_create_response_makes_the_response_stale` | Pass |
| 11 late processing ignored; no succeeded/failed flip; replays no-op | `test_a_retried_processing_webhook_after_succeeded_is_ignored`, `test_succeeded_never_flips_to_failed`, `test_failed_never_flips_to_succeeded`, `test_replaying_one_snapshot_applies_it_once` | Pass |
| 12 identity mismatch refused and logged at error | `TransitionGenerationActionConflictsTest::test_a_snapshot_of_another_generation_is_refused_and_logged`, `test_the_opposite_terminal_status_is_ignored_and_logged` | Pass |
| 13 local failure: applied on pending, refused once accepted, ignored on terminal | `test_a_local_failure_after_pixelforge_accepted_the_row_is_refused_and_logged`, `test_a_local_failure_on_a_terminal_row_is_not_logged` | Pass |
| 14 two connections, one applied, one duplicate | `TransitionGenerationActionConcurrencyTest::test_two_simultaneous_appliers_of_one_snapshot_write_the_row_once` (ran in the full suite, not skipped) | Pass |
| 15 UTC instants exact under `Europe/Bucharest` | `GenerationTest::test_a_pixelforge_instant_survives_a_non_utc_php_and_database_timezone`, `TransitionGenerationActionTest::test_an_applied_snapshot_keeps_its_instants_exact_under_a_non_utc_session`, `GenerationSnapshotTest::test_an_offset_instant_is_converted_to_utc...` | Pass |
| 16 factory states satisfy invariants | `GenerationFactoryTest` (six tests, one per state plus the pending definition) | Pass |

## Edge cases probed

Probes ran as a temporary test file in the chain container's test database, under `RefreshDatabase`. The file was deleted afterwards and is not committed. The worktree is clean.

- Empty prompt `""`, no references: stored as `""`. Estimate 14,000,000 / 17,906,250 / 21,812,500 n$. Pass.
- 3,000 code points (9,500 bytes), mixing skin-tone emoji, decomposed `é` (U+0301), CJK, and four reference URLs with query strings: prompt and reference order read back byte-identical. Typical 435,750,000 n$. Pass.
- Invalid UTF-8 prompt `"a\xFFb"`: `Illuminate\Database\QueryException`, no row written. Pass, as the plan says.
- **NUL byte prompt `"a\0b"`: no exception, and a row is written with the prompt stored as `a` (hex `61`, 1 byte).** The estimate was priced on the 3-character original. **Fail.** See Failure 1.

Not probed: the 4,000-code-point limit and TrimStrings, which belong to PE-011.

## Failures

1. [x] **AC3: a NUL byte silently truncates the stored prompt.** Repro: `CreateGenerationAction::execute($user, new NewGenerationData("a\0b", ImageSize::Square, ImageQuality::Low, []))` returns a row with no error, and `generations.prompt` is `a`. Expected: either the prompt is stored byte-identical, or the action refuses the prompt before the insert and writes no row. The plan's Risks section says the insert throws and rolls back, and that assumption is wrong for NUL (invalid UTF-8 does throw). Fix: reject a prompt containing `\0` in `CreateGenerationAction` before the insert, with a test row in the AC3 or refusal test. Then correct the Risks line in `plan.md`.
   - **Resolved** in `5867da4` and `c421ce6`. `CreateGenerationAction` now throws `InvalidGenerationRequestException` before pricing or the insert when the prompt contains `\0` (test rows: start, middle, end), and no row is written.
   - Reference image URLs checked: a NUL in a URL is not truncated (the `json` column keeps it as the `\u0000` escape and it round-trips), but no URL holds a NUL, so it is refused the same way and the message names the one-based position (test rows: start, middle, end, second of two).
   - The plan's Risks line is corrected: only invalid UTF-8 throws on insert; NUL is refused by the action.

## Visual verification

No UI in this story (`story.md`: "No UI"). This is a backend-only story, so no browser pass, screenshots, horizontal-scroll check or console check applies. The chain worktree has no browser or `make e2e` target. The orchestrator re-runs the E2E suite on the main stack.

No fixture rows were written to a shared database. All probes ran in the isolated test database under `RefreshDatabase`.

## Notes (non-blocking)

- The PR size is still unresolved from review round 1. The user must choose between one PR and a split at `d9498b2` before the PR is opened. The PR description must state the size and the choice.
- **For PE-011:** the submit endpoint's server validation must reject a prompt or reference image URL containing a NUL byte with a field error, before `CreateGenerationAction` is reached. The action's `InvalidGenerationRequestException` is the domain's last line, not the user-facing message.
