# QA — PE-008-verified-idempotent-webhooks

**Verdict: QA PASSED**

Every AC maps to a passing test or, for the two docs ACs, to the README and decisions-log rows read in the worktree. No review item is Critical, so nothing auto-fails. Status moves to `qa-passed`.

Prerequisites checked: story status `qa-ready`, review verdict `APPROVE` (round 1). Branch `pe-008-verified-idempotent-webhooks` in the isolated worktree, clean at the start of this run. Checks ran in the `prompt-edit-chain` container only.

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Full check | `chain-make.sh chain check` | Exit 0. Pint and Larastan clean. PHPUnit 745 passed (3,612 assertions), none skipped. ESLint, Prettier and vue-tsc clean. Vitest 107 passed (9 files). |
| Pixelforge subset | `chain-make.sh chain test Pixelforge` | Exit 0. 204 passed (1,071 assertions). |
| Webhook subset | `chain-make.sh chain test Webhook` | Exit 0. 134 passed (853 assertions). |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-008/check.log`, `.../PE-008/test-pixelforge.log`, `.../PE-008/test-webhook.log`.

## Acceptance criteria

Test names are in `tests/Feature/Webhooks/*` and `tests/Unit/Support/Pixelforge/WebhookSignatureTest.php`, abbreviated to the method name.

| AC | Covered by | Result |
| --- | --- | --- |
| 1 signed request gets 2xx with no session, CSRF or user; no cookie; never a 3xx; 100 pings unthrottled | `test_a_signed_ping_is_accepted_without_a_session_csrf_token_or_user` (asserts no cookies and no `Set-Cookie`); `test_the_route_runs_only_the_signature_middleware`; `test_a_hundred_back_to_back_pings_are_all_accepted` | Pass |
| 2 re-encoded body under the original signature is 400 | `test_the_same_event_re_encoded_under_the_original_signature_is_a_mismatch` | Pass |
| 3 missing header, no t, no v1, non-numeric t, other secret, one-byte change, wrong t: 400, nothing persisted | `test_a_request_without_a_valid_signature_is_rejected_and_changes_nothing` (7 rows); unit `test_an_absent_or_blank_header_is_missing`, `test_a_header_that_does_not_parse_is_malformed`, `test_a_signature_made_with_another_secret_is_a_mismatch`, `test_a_body_changed_by_one_byte_is_a_mismatch`, `test_a_valid_signature_for_another_timestamp_is_a_mismatch` | Pass |
| 4 ±300 accepted, ±301 rejected, frozen time | `test_the_replay_window_is_300_seconds_either_way` (4 rows); unit `test_a_timestamp_within_the_tolerance_is_accepted`, `test_a_timestamp_beyond_the_tolerance_is_stale` | Pass |
| 5 each rejection logs its reason and event id; no log line carries the secret or the body | `assertRejectionLogged` in the rejection tests, `test_a_rejection_without_an_event_id_header_logs_a_null_event_id`; `test_no_log_line_carries_the_secret_or_the_body` (8 outcomes, sentinel secret) | Pass |
| 6 no secret: every request 500, critical log, empty key never used | `test_without_a_secret_every_request_is_a_500_logged_as_critical` (6 rows); unit `test_signing_without_a_secret_throws`, `test_verifying_without_a_secret_throws` | Pass |
| 7 validly signed unreadable bodies: 400, nothing persisted, error log; ping with or without object accepted | `test_a_signed_body_that_cannot_be_read_is_refused_with_400_and_persists_nothing`; `test_a_generation_event_whose_object_is_not_a_generation_is_refused`; `test_a_succeeded_event_whose_snapshot_cannot_be_read_is_refused_and_persists_nothing`; `test_a_ping_is_recorded_with_or_without_an_object_and_touches_no_generation` | Pass |
| 8 Lighthouse succeeded: 305,510,000 / 381,887,500, net −381,887,500, one event record, no outbound HTTP | `test_a_succeeded_event_charges_the_lighthouse_its_actual_cost_and_records_the_event` (asserts `Http::assertNothingSent()`) | Pass |
| 9 same event again, and as a resend with attempt 2 and a fresh t: no-op | `test_the_same_event_again_later_or_as_a_dashboard_resend_is_a_no_op` | Pass |
| 10 racing duplicates on separate connections: both 2xx, one row, one charge | `PixelforgeWebhookConcurrencyTest::test_two_racing_deliveries_of_one_event_record_and_charge_once` | Pass |
| 11 two event ids, same snapshot: two rows, one charge | `test_two_event_ids_with_the_same_succeeded_snapshot_charge_once` | Pass |
| 12 processing after succeeded; failed after succeeded; succeeded after provider failed: recorded, nothing changes | `test_an_out_of_order_event_is_recorded_and_changes_nothing` (3 rows, raw-row and ledger compared after a one-minute travel) | Pass |
| 13 succeeded before create response, matched by our ULID, charged once | `test_a_succeeded_event_before_the_create_response_is_matched_by_our_ulid` | Pass |
| 14 no usable generation_id: matched by stored provider id | `test_an_event_without_our_generation_id_is_matched_by_the_stored_provider_id` | Pass |
| 15 contradicting provider id: recorded, not applied, anomaly logged with both ids | `test_an_event_whose_id_contradicts_the_stored_provider_id_is_recorded_but_refused`; `test_an_event_with_a_foreign_generation_id_but_our_provider_id_is_recorded_but_refused` | Pass |
| 16 failed with `generation_failed` or `internal_error`: failed, code and message stored, 0 / 0, net 0 | `test_a_failed_event_is_free_and_releases_the_hold` (2 rows) | Pass |
| 17 processing on queued: processing, started_at set, no ledger entry | `test_a_processing_event_starts_a_queued_generation_without_a_ledger_entry` | Pass |
| 18 snapshot status decides; mismatch logged | `test_the_snapshot_status_decides_when_the_event_type_disagrees` | Pass |
| 19 D-09 through the endpoint: stays failed, cost 305,510,000, charge 0 | `test_a_succeeded_event_for_a_locally_failed_lighthouse_records_its_cost_and_charges_nothing` | Pass |
| 20 ping recorded, touches no generation | `test_a_ping_is_recorded_with_or_without_an_object_and_touches_no_generation`; `test_the_same_ping_again_is_a_silent_no_op_that_keeps_the_first_attempt` | Pass |
| 21 unknown type: recorded, warning, nothing changes | `test_an_unknown_event_type_is_recorded_with_a_warning_and_changes_nothing` | Pass |
| 22 unknown generation, malformed or 500-character generation_id: recorded without one, warning, no DB error | `test_an_event_for_an_unknown_generation_is_recorded_without_one_and_changes_nothing` (data provider); `test_our_ulid_followed_by_a_nul_byte_never_matches_our_row`; `test_a_255_character_id_is_recorded` | Pass |
| 23 apply throws: event, status and ledger roll back, 500; redelivery charges once | `PixelforgeWebhookRollbackTest::test_a_failing_ledger_write_rolls_the_event_back_and_a_redelivery_charges_once`; `test_a_rate_that_cannot_be_priced_exactly_rolls_the_event_back` | Pass |
| 24 dedupe and routing use the signed body; header disagreement logged | `test_dedupe_uses_the_signed_body_id_not_the_event_id_header`; `test_routing_uses_the_signed_body_type_not_the_event_type_header` | Pass |
| 25 handling makes no outbound HTTP | `Http::assertNothingSent()` in the AC 8 test; `Http::preventStrayRequests()` in `DeliversPixelforgeWebhooks` | Pass |
| 26 README explains local development with a tunnel, the dev key, `.env` secret and "Send test event" | Read `README.md` "Real webhooks locally". Covers the three tunnels, the dev key URL, the `.env` secret, "Send test event", and how to read each refusal. Fake driver stays default. | Pass (by reading; docs carry no test) |
| 27 decisions-log rows for 2xx on unknown, signed body over headers, recording every verified id | Read `.agent-workflow/requirements.md` D-28, D-29, D-30 (D-27 narrowed PE-006 AC12). Next free numbers after D-27. | Pass (by reading) |

## Money check

Recomputed from the story's figures, separately from the code.

- Lighthouse hold: 457,150,000 n$. Succeeded provider cost: 60,000 + 7,650,000 + 258,000,000 + 39,800,000 = 305,510,000 n$. User charge: 305,510,000 × 1.25 = 381,887,500 n$. Net −381,887,500 n$.
- Ledger net: hold −457,150,000 + release +457,150,000 + charge −381,887,500 = −381,887,500 n$, as the story states.

Each charge is asserted once in the feature tests, including after replay, duplicate and race.

## Edge cases

No scratch probes were added. The edge cases are covered by the named tests above: boundary ages, re-encoded body, empty and unset secrets, NUL bytes in the id, 500-character ids, racing duplicates, rollback and redelivery, and D-09. All pass in the full run.

## Regressions

The full PHPUnit, Vitest, Larastan, Pint, ESLint, Prettier and vue-tsc suites pass. The PE-007 ledger and transition tests run inside the same suite and still pass.

## Visual verification

No UI in this story (`story.md`: "No UI"). It is backend only, so no browser pass, screenshots, horizontal-scroll check, console check or E2E run applies in this worktree, and none was attempted. The orchestrator re-runs the E2E suite on the main stack.

No fixture rows were written to any shared database. Every test ran in the isolated `prompt_edit_test_chain` database. Nothing was written to the main stack.

## Notes (non-blocking)

These are open review suggestions S1 to S5. None is Critical, and none changes an AC result.

- S1: D-30 says a redelivery is "never a second warning", but a header mismatch is logged on every delivery. The rule needs its wording tightened.
- S2: rejection logs carry `Pixelforge-Event-Id` exactly as sent, with no size limit. Pings are unauthenticated and throttling is ruled out by AC 1, so a large header can be written to the log. Capping it at 255 characters would close this.
- S3: commit `3d21aac [PE-008] Identify a snapshot without our generation id by its stored provider id` is 80 characters, over the 72 limit. History is not rewritten in this pipeline.
- S4: the four Pixelforge header names are repeated as string literals in the middleware and the controller.
- S5: the AC 5 sentinel test checks that the whole body is absent from logs. A log line holding only part of the body would not fail it.
