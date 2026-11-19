# QA — PE-009-pixelforge-client-and-submit-job

**Verdict: QA PASSED**

Every AC maps to a passing test. Review round 2 is APPROVE with no open Critical items, so nothing auto-fails. Status moves to `qa-passed`.

Prerequisites checked: story status `qa-ready`, review verdict `APPROVE` (round 2; round 1 was REQUEST CHANGES and its findings are fixed). Branch `pe-009-pixelforge-client-and-submit-job` in the isolated worktree, clean at the start of this run, HEAD `4b397bc`. Checks ran only in the `prompt-edit-chain` container.

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Full check | `chain-make.sh chain check` | Exit 0. Pint and Larastan clean. PHPUnit 916 passed (4,472 assertions), none skipped. ESLint, Prettier and vue-tsc clean. Vitest 107 passed (9 files). |

Log: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-009/check.log`.

## Acceptance criteria

Test names are in `tests/Feature/Pixelforge/*`, `tests/Feature/Jobs/*`, `tests/Feature/Production/BootsWithoutPixelforgeTest.php` and `tests/Unit/Support/Pixelforge/*`, abbreviated to the method name.

| AC | Covered by | Result |
| --- | --- | --- |
| 1 unset or `http` resolves to HTTP driver; `htp` fails naming it | `test_the_http_driver_resolves_the_http_client_afresh_each_time`; `test_an_unknown_driver_fails_naming_it` (asserts `"htp"` in message); `BootsWithoutPixelforgeTest::test_the_pixelforge_client_resolves_to_the_http_driver` | Pass |
| 2 POST to `{base}/generations`, four headers | `test_a_create_is_posted_to_the_generations_endpoint`; `test_a_configured_base_url_is_used_as_given`; `test_it_sends_exactly_the_documented_headers` | Pass |
| 3 Lighthouse body decodes to exactly the documented fields; no reference key when empty | `test_the_lighthouse_body_decodes_to_exactly_the_documented_fields`; `test_no_reference_images_sends_no_reference_key`; `SubmitGenerationJobTest::test_the_lighthouse_row_is_sent_as_exactly_the_documented_body`; unit `CreateGenerationPayloadDataTest` (3 cases) | Pass |
| 4 Unicode prompt (50 code points) sent unchanged | `test_a_unicode_prompt_is_sent_unchanged` (feature and unit) | Pass |
| 5 two attempts: identical bodies and key, second reloads the row | `SubmitGenerationJobTest::test_two_attempts_send_byte_identical_bodies_under_the_same_key` (reloads before each attempt); unit `test_equal_fields_encode_byte_identically`, `test_the_idempotency_key_is_the_generation_ulid` | Pass |
| 6 202 returns snapshot and `replayed` flag from `Idempotent-Replayed` | `test_a_202_returns_the_snapshot_and_whether_it_was_replayed` (data provider, true and false) | Pass |
| 7 each documented envelope becomes a typed error with code, type, status, message, param, errors, Retry-After | `test_each_documented_error_envelope_becomes_its_typed_error` (9 rows: 429 gives 17, 5, none; 503 gives 2); `test_a_validation_failure_keeps_its_param_and_field_errors`; unit `PixelforgeApiExceptionTest` (9 cases) | Pass |
| 8 timeouts, refused connection, no envelope, undocumented code, non-generation 2xx: ambiguous | `test_a_transport_failure_is_ambiguous`; `test_a_response_without_a_documented_envelope_is_ambiguous`; `test_a_response_cut_off_after_its_status_line_is_ambiguous`; `test_a_2xx_that_is_not_a_generation_object_is_ambiguous` | Pass |
| 9 empty or null key: not-configured error, no request | `test_no_key_raises_not_configured_and_sends_nothing` (null and empty) | Pass |
| 10 key in no log, exception, serialized payload or output (401, timeout too) | `test_the_api_key_appears_in_no_log_or_exception` (202, 401, read timeout, 503 and cut-off variants); `test_a_trace_through_the_constructor_redacts_the_key`; `DispatchTest::test_the_queued_payload_carries_neither_the_key_nor_the_prompt` | Pass |
| 11 dispatch after the row and hold commit; nothing on rollback | `SubmitGenerationDispatchTest::test_creating_a_generation_queues_one_submit_job_carrying_its_ulid`; `test_the_job_is_pushed_only_after_the_actions_transaction_commits`; `test_a_caller_transaction_that_rolls_back_queues_nothing`; `test_a_hold_that_fails_inside_the_action_queues_nothing` | Pass |
| 12 row and hold readable from another connection before the HTTP call | `SubmitGenerationAfterCommitTest::test_the_row_and_its_hold_are_committed_before_the_create_request`. It uses `DatabaseTruncation`, not `RefreshDatabase`, so commits are real | Pass |
| 13 202 queued: stays queued, provider id stored, net −457,150,000 | `SubmitGenerationJobTest::test_a_queued_answer_leaves_the_generation_queued_with_its_hold` | Pass |
| 14 no-op when a provider id exists or the status is terminal | `SubmitGenerationJobTest::test_a_generation_with_a_provider_id_or_a_terminal_status_is_not_sent` (data provider) | Pass |
| 15 replayed succeeded snapshot charged once; later webhook adds nothing | `test_a_replay_that_has_moved_on_to_succeeded_is_charged_once` (305,510,000 cost, 381,887,500 charge, net −381,887,500) | Pass |
| 16 webhook during the in-flight create is not undone | `test_a_webhook_applied_while_the_create_is_in_flight_is_not_undone` | Pass |
| 17 lost response, retry with same key: one `gen_` id, one hold, no duplicate entry | `test_a_lost_response_is_recovered_by_the_replay_of_the_same_generation` | Pass |
| 18 definitive 400, 422, 409 fail locally at net 0, reasons kept, errors logged where required | `SubmitGenerationRejectionsTest::test_a_definitive_rejection_fails_the_generation_at_no_charge` (3 rows: reason, message, param, logged error count, ledger hold and release) | Pass |
| 19 every other error rethrown, same key and body, generation keeps hold (401, 404, each 429, 503, not-configured, ambiguous) | `SubmitGenerationRejectionsTest::test_every_other_failure_is_rethrown_and_the_generation_keeps_its_hold` (data provider) | Pass |
| 20 out of attempts: non-terminal with hold, logged with ULID, never failed locally | `test_running_out_of_attempts_logs_the_failure_and_leaves_the_generation_waiting`; `SubmitGenerationLoggingTest::test_the_give_up_line_carries_the_ulid_and_the_failure` | Pass |
| 21 log lines carry ULID, provider id, code, replayed flag; never key or body | `SubmitGenerationLoggingTest::test_each_submission_line_carries_its_ids_and_outcome`; `test_nothing_left_behind_carries_the_key_the_prompt_or_the_body` | Pass |

Also in scope and passing: `PixelforgeConfigTest` (defaults, timeouts as integers, invalid timeouts fall back), `HttpPixelforgeClientTest::test_a_redirect_is_never_followed`, `SubmitGenerationDispatchTest::test_the_timeouts_nest_below_the_queue_retry_window`, and `BootsWithoutPixelforgeTest` (app boots and serves with no Pixelforge variable).

## Money check

Recomputed from the story's figures, separately from the code.

- Lighthouse hold: 457,150,000 n$.
- Queued (AC 13): hold −457,150,000, no charge, net −457,150,000 n$.
- Replayed succeeded (AC 15): provider cost 60,000 + 7,650,000 + 258,000,000 + 39,800,000 = 305,510,000 n$. Charge 305,510,000 × 1.25 = 381,887,500 n$. Net −381,887,500 n$.
- Definitive rejection (AC 18): hold −457,150,000, release +457,150,000, net 0. Provider cost 0, charge 0.
- Each charge is asserted once in the feature tests, including after replay.

## Edge cases

Probe, scratch only, deleted after the run and never committed. `tests/Feature/ScratchProbe/StrayRequestProbeTest.php` had two cases:

- A raw `Http::post` to the real Pixelforge URL, with no fake, throws `StrayRequestException`.
- Dispatching `SubmitGenerationJob` for a Lighthouse row with no fake throws `StrayRequestException`. The message names the host and not the key. `Http::assertNothingSent()` passes and the row keeps no provider id.

Result: 2 passed, 5 assertions. `Http::preventStrayRequests()` is set in `tests/TestCase.php` `setUp()` and every test class here extends it. The `phpunit.xml` env sets `PIXELFORGE_API_KEY` to empty with `force="true"`, so the suite never runs with the local key.

Other edge cases are covered by the named tests above: non-positive and non-integer timeouts, a 422 cut off mid-body, the 2xx that is not a generation, Retry-After only in whole seconds, and a 255-character param.

## Regressions

The full PHPUnit suite passes, including the PE-006, PE-007 and PE-008 ledger, transition and webhook tests. Vitest, Larastan, Pint, ESLint, Prettier and vue-tsc all pass.

Note: `git diff main..HEAD` shows PE-005 UI files as deleted. The branch is based on the PE-008 line and does not contain the PE-005 merge, so this is a branch-base difference and not a regression from this story. Against the merge base, the branch holds 22 commits, all `[PE-009]`, 43 files changed.

## Visual verification

No UI in this story (`story.md`: "No UI"). It is backend only, so no browser pass, screenshots, horizontal-scroll check, console check or E2E run applies here, and none was attempted. The orchestrator re-runs the E2E suite on the main stack.

No fixture rows were written to any shared database. Every test ran in the isolated `prompt_edit_test_chain` database. Nothing was written to the main stack, and no real Pixelforge request was made.

## Notes (non-blocking)

- Review round 2 suggestions were all fixed in `65c32ae`, `0251d57` and `f8cc926`. Nothing is open.
