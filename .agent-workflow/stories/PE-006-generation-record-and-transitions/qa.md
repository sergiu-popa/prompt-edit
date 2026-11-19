# QA — PE-006-generation-record-and-transitions

**Verdict: QA PASSED**

Re-run after the round 1 fix commits (`5867da4`, `c421ce6`, `717efcc`) on branch `pe-006-generation-record-and-transitions` at `717efcc`. Prerequisites checked: story status `qa-ready`, review verdict `APPROVE` (round 2).

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Full check | `chain-make.sh chain check` | Exit 0. Pint: no errors. Larastan: no errors. PHPUnit 559 passed (2,132 assertions), none skipped, risky or incomplete. ESLint, Prettier and vue-tsc clean. Vitest 107 passed. |

Log: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-006/check-rerun.log`.

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 pending row, worked example 95,237,500 / 109,312,500 / 123,387,500 | `CreateGenerationActionTest::test_it_writes_a_pending_row_with_the_server_side_estimate` | Pass |
| 2 creation in one transaction, no row on failure | `CreateGenerationActionTest::test_a_failing_write_leaves_no_row` | Pass |
| 3 request fields read back byte-identical | `test_the_prompt_reads_back_byte_identical`, `test_the_reference_images_read_back_in_order`, `test_a_prompt_with_a_nul_byte_is_refused_before_anything_is_written`, `test_a_reference_image_with_a_nul_byte_is_refused_before_anything_is_written` | Pass |
| 4 ULID is the only external key, `user_id` the only integer key | `GenerationTest::test_the_ulid_is_the_key_and_no_column_draws_from_a_sequence`, `test_the_owner_is_the_only_foreign_key`, `test_the_model_uses_a_lowercase_ulid_string_key` | Pass |
| 5 unique nullable provider id | `GenerationTest::test_any_number_of_rows_may_have_no_provider_id_but_a_provider_id_is_unique` | Pass |
| 6 snapshot parses from object and webhook `data.object` | `GenerationSnapshotTest::test_the_docs_create_response_parses`, `test_the_docs_succeeded_example_parses`, `test_metadata_generation_id_is_read...`, `test_unknown_top_level_fields_are_ignored` | Pass |
| 7 typed exceptions for each rejection; failed with null error parses | `test_a_malformed_object_is_rejected_naming_the_field`, `test_a_succeeded_snapshot_without_its_result_is_rejected`, `test_a_usage_that_drops_or_misstates_a_token_type_is_rejected`, `test_rates_that_drop_or_add_a_token_type_are_rejected`, `test_a_rate_that_is_not_a_decimal_string_is_rejected`, `test_a_failed_snapshot_without_an_error_parses_as_a_generic_failure` | Pass |
| 8 forward-only table, every pair | `TransitionGenerationActionTest::test_each_snapshot_pair_reports_its_outcome_and_only_an_applied_one_writes` (data provider), `test_a_local_failure_reports_its_outcome_and_only_an_applied_one_writes` | Pass |
| 9 applied records fields; ignored and conflicting write nothing | `test_an_applied_queued_snapshot_records_the_provider_id_and_creation_time`, `test_an_applied_processing_snapshot_records_when_it_started`, `test_an_applied_succeeded_snapshot_records_the_whole_result`, `test_an_applied_failed_snapshot_records_the_error`, `test_an_applied_local_failure_records_the_reason_and_the_providers_error`; raw row compared under time travel in the table test | Pass |
| 10 webhook before create response | `TransitionGenerationActionSequencesTest::test_a_succeeded_webhook_before_the_create_response_wins_and_the_response_changes_nothing`, `test_a_processing_webhook_before_the_create_response_makes_the_response_stale` | Pass |
| 11 late processing ignored; no succeeded/failed flip; replays no-op | `test_a_retried_processing_webhook_after_succeeded_is_ignored`, `test_succeeded_never_flips_to_failed`, `test_failed_never_flips_to_succeeded`, `test_replaying_one_snapshot_applies_it_once` | Pass |
| 12 identity mismatch refused and logged at error | `TransitionGenerationActionConflictsTest::test_a_snapshot_of_another_generation_is_refused_and_logged`, `test_the_opposite_terminal_status_is_ignored_and_logged` | Pass |
| 13 local failure: applied on pending, refused once accepted, ignored on terminal | `test_a_local_failure_after_pixelforge_accepted_the_row_is_refused_and_logged`, `test_a_local_failure_on_a_terminal_row_is_not_logged` | Pass |
| 14 two connections, one applied, one duplicate | `TransitionGenerationActionConcurrencyTest::test_two_simultaneous_appliers_of_one_snapshot_write_the_row_once` (ran in the full suite, not skipped) | Pass |
| 15 UTC instants exact under `Europe/Bucharest` | `GenerationTest::test_a_pixelforge_instant_survives_a_non_utc_php_and_database_timezone`, `TransitionGenerationActionTest::test_an_applied_snapshot_keeps_its_instants_exact_under_a_non_utc_session`, `GenerationSnapshotTest::test_an_offset_instant_is_converted_to_utc_whatever_the_php_timezone` | Pass |
| 16 factory states satisfy invariants | `GenerationFactoryTest` (six tests, one per state plus the pending definition) | Pass |

## Edge cases probed

Probes ran as a temporary test file in the chain worktree under `RefreshDatabase`, using the chain test database. The file was deleted afterwards and is not committed. `git status` is clean.

NUL re-probe (this round), each call to `CreateGenerationAction::execute`:

- Prompt NUL at start `"\0ab"`, middle `"a\0b"`, end `"ab\0"`, only `"\0"`, and doubled `"a\0\0b"`: each throws `InvalidGenerationRequestException` with "The prompt contains a NUL byte, which cannot be stored.", and the `generations` count is unchanged. Pass.
- Reference URL NUL at start, middle and end of the first URL: each throws "Reference image 1 contains a NUL byte, which cannot be stored.", no row written. Pass.
- Reference URL NUL in the second of two URLs: throws "Reference image 2 contains ...", no row written. Pass.
- Invalid UTF-8 prompt `"a\xFFb"`: `Illuminate\Database\QueryException`, no row written. Pass, as before.
- Clean control: prompt `"  cafe\u{0301} 🎨👍🏽 東京  "` with one reference URL containing a query string stores and reads back byte-identical, and one row is written. Pass.

Carried over from the round 1 probes, not re-run this round: empty prompt `""` (stored as `""`, estimate 14,000,000 / 17,906,250 / 21,812,500 n$) and a 3,000 code-point prompt with four reference URLs (read back byte-identical). The round-trip data provider in `CreateGenerationActionTest` covers the same classes of input.

Not probed: the 4,000-code-point limit and TrimStrings, which belong to PE-011.

## Failures

None. The round 1 failure (AC3, NUL byte silently truncating the stored prompt) is resolved and re-verified above: the action refuses a NUL in the prompt or in a reference URL before the insert, and writes no row. The plan's Risks line now says only invalid UTF-8 throws on insert, and that NUL is refused by the action.

## Visual verification

No UI in this story (`story.md`: "No UI"). This is a backend-only story, so no browser pass, screenshots, horizontal-scroll check or console check applies. The chain worktree has no browser or `make e2e` target. The orchestrator re-runs the E2E suite on the main stack.

No fixture rows were written to a shared database. All probes ran in the isolated chain test database under `RefreshDatabase`.

## Notes (non-blocking)

- The PR size is still unresolved from review round 1. The user must choose between one PR and a split at `d9498b2` before the PR is opened. The PR description must state the size and the choice.
- **For PE-011:** the submit endpoint's server validation must reject a prompt or reference image URL containing a NUL byte with a field error, before `CreateGenerationAction` is reached. The action's `InvalidGenerationRequestException` is the domain's last line, not the user-facing message.
