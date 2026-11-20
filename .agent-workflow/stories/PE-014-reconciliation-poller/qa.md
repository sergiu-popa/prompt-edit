# QA — PE-014-reconciliation-poller

**Verdict: QA PASSED**

Run on branch `pe-014-reconciliation-poller` at `fb5d35d`, 2026-11-20 13:35 to about 13:45 UTC. Prerequisites checked: story status `qa-ready`, review round 2 verdict `APPROVE`, no Critical review item open and no open Warning. The worker was restarted (`make restart-worker`). The scheduler and worker env match the dev reconcile values: `PIXELFORGE_RECONCILE_EVERY=5`, `_QUEUED_AFTER=5`, `_PROCESSING_AFTER=5`, `_RECHECK=2`. The frontend was built (`docker exec prompt-edit-app npm run build`).

Scratch root: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-014/`

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Lint, Larastan, PHPUnit, JS lint/format/types, Vitest | `make check` (includes `js-check` and `js-test`) | Exit 0. Pint PASS (277 files). Larastan no errors. PHPUnit 1,654 passed (36,709 assertions). ESLint, Prettier and vue-tsc clean. Vitest 498 passed in 31 files. |
| Existing E2E, desktop and mobile | `make e2e` | Exit 0. 42 passed, 2 skipped (the existing touch-only and mouse-only rows). Includes the AC 22 `[no-webhooks]` spec, which passed on both projects. |
| Temporary browser pass, 375 / 768 / 1440 × desktop and mobile | `tests/e2e/qa-shots.spec.ts`, two test groups | 12 of 12 passed. The spec is deleted from the repo. |

Logs: `.../PE-014/check.log`, `.../PE-014/e2e-full.log`, `.../PE-014/qa-shots.log`, `.../PE-014/qa-failpoll.log`.

## Acceptance criteria

Test names are from this branch. "Browser" means the temporary pass against the dev stack; "test" means PHPUnit or Vitest only.

| AC | Covered by | Result |
| --- | --- | --- |
| 1 retrieve op: 200 → DTO, typed errors, one budget unit, no inline retry | `HttpPixelforgeClientTest` (`a_retrieve_returns_the_generation_as_it_is_now`, `a_documented_retrieve_error_becomes_its_typed_error`, `a_retrieve_answer_that_is_no_generation_or_typed_error_is_ambiguous`); `ThrottledPixelforgeClientTest::test_a_retrieve_takes_one_unit_of_the_same_budget`; `ReconcilePollTest::a_failed_poll_is_logged_and_waits_for_the_next_check` | Pass |
| 2 fake retrieve from its state; unknown id → `not_found` | `FakePixelforgeRetrieveTest` (`after_each_lifecycle_step_it_answers_the_stored_state`, `an_unknown_id_is_not_found`) | Pass |
| 3 `[no-webhooks]` drops webhooks for one generation; global switch off by default | `FakeKeywordsTest`, `FakePixelforgeLifecycleTest` (drops only the silent generation), `PixelforgeConfigTest` (switch default false); browser: 0 webhook events for all 18 `[no-webhooks]` rows (DB below) | Pass |
| 4 runs every minute, one server, no overlap; one bad row logged, run continues | `ReconcileGenerationsCommandTest` (`the_schedule_runs_it_every_minute_on_one_server_without_overlapping`, `it_succeeds_when_one_generation_throws`, `a_sub_minute_frequency_repeats_inside_the_minute`) | Pass |
| 5 stale by last provider update, 2 / 3 min, terminal excluded, `updated_at` not used | `ReconcilePolicyTest` (queued 2 min, processing 3 min, `statusesNeverPolled`); `ReconcilePollTest::a_processing_row_is_polled_only_once_its_last_update_is_3_minutes_old`. Browser: the reconciler polled the queued rows at the 5 s threshold | Pass |
| 6 unchanged status writes no ledger rows; cadence and 4 polls per hour at 2 h | `ReconcilePollTest` (`an_unchanged_status_writes_nothing_but_the_next_check`, `a_row_stuck_for_2_hours_is_polled_at_most_4_times_an_hour`); `ReconcilePolicyTest` (cadence) | Pass |
| 7 `succeeded` settles once with the worked-example numbers | `ReconcileSettlementTest::a_succeeded_poll_charges_the_red_fox_its_actual_cost_once` (provider 15,125,000, charge 18,906,250). Browser: 6 `[succeed] [no-webhooks]` rows, each with one hold, one release, one charge and charge = ceil(provider × 1.25) | Pass |
| 8 later duplicate `succeeded` webhook is 200 and writes nothing | `ReconcileSettlementTest::a_later_succeeded_webhook_delivered_twice_writes_nothing` | Pass |
| 9 `failed` poll releases the hold, charges nothing, stores error | `ReconcileSettlementTest::a_failed_poll_releases_the_hold_and_history_explains_it`. Browser: 6 `[fail] [no-webhooks]` runs, Failed with the `[fail]` copy, Not charged, `generation_failed` in the DB | Pass |
| 10 never moves backwards; processing advances queued without a ledger row | `ReconcilePollTest` (`a_processing_poll_advances_a_queued_row_without_a_ledger_entry`, `a_queued_poll_changes_nothing_on_a_processing_row`) | Pass |
| 11 webhook and poll in either order, or overlapping, charge once | `ReconcileSettlementTest` (`a_webhook_then_a_poll_charge_once`, `a_poll_then_a_webhook_charge_once`, `a_webhook_delivered_during_the_poll_charges_once`) | Pass |
| 12 re-dispatch for handed-over rows, or no attempt for 5 min (6 min yes, 4 min no) | `ReconcileRedispatchTest` (`a_row_last_attempted_6_minutes_ago_is_redispatched_and_one_4_minutes_ago_is_not`, `a_handed_over_row_is_redispatched_with_its_ulid_and_the_runs_instant_then_waits_its_turn`); `ReconcilePolicyTest` (`an_unsubmitted_row_is_redispatched_once_its_last_attempt_is_older_than_5_minutes`); test only | Pass (test) |
| 13 same row, same ULID key and bytes; one attempt past the window; hand-back if not definitive | `SubmitGenerationRedispatchTest` (`a_handed_over_row_past_its_window_sends_one_create_with_its_first_attempts_key_and_bytes`, `a_timeout_on_the_owed_run_leaves_it_handed_over_with_its_hold`) | Pass (test) |
| 14 replay (202, `Idempotent-Replayed`) applied once; create now if not landed | `ReconcileRedispatchTest` (`a_replayed_succeeded_create_is_applied_once`, `a_create_that_had_not_landed_is_created_now`); `SubmitGenerationRedispatchTest::a_202_on_the_owed_run_leaves_it_queued_with_its_provider_id` | Pass (test) |
| 15 at most one create in flight per generation | `SubmitGenerationCreateLockTest`, `SubmitGenerationRetriesTest` | Pass (test) |
| 16 at most `PIXELFORGE_RECONCILE_BATCH` rows per run, oldest first | `ReconcilePollTest::a_run_polls_at_most_the_batch_oldest_first` | Pass |
| 17 polls stop below `PIXELFORGE_SUBMIT_RESERVE` (12 left → 2 polls) | `ReconcileBudgetTest` (`with_12_units_left_only_2_polls_are_sent`, `a_spent_budget_sends_no_poll_even_with_no_reserve`) | Pass |
| 18 `not_found` logged as a warning with both ids, row unchanged | `ReconcilePollTest::not_found_is_a_warning_naming_both_ids_and_changes_nothing` | Pass (test) |
| 19 stuck notice after 15 min, one warning per row; History copy | `ReconcileStuckTest`; `HistoryItem.test.ts` (integration, the stuck copy). Not reachable in the browser: dev keeps the 900 s default. | Pass (test) |
| 20 `lost` after 24 h for rows with no provider id, free, error log; late success records provider cost only | `ReconcileGiveUpTest` (`a_day_after_creation_a_handed_over_row_is_failed_lost_and_free`, `a_lost_row_that_later_succeeds_records_the_provider_cost_only`); `ReconcilePolicyTest::an_unconfirmed_row_is_given_up_once_it_is_a_day_old`; `HistoryItem.test.ts` (the `lost` copy). Not reachable in the browser. | Pass (test) |
| 21 never give up a row with a provider id; log once after 24 h of `not_found`; keep hold and notice | `ReconcileGiveUpTest` (`a_confirmed_row_not_found_for_over_a_day_is_reported_once_and_keeps_its_hold`, `a_day_of_not_found_is_not_yet_too_long`) | Pass (test) |
| 22 E2E: `[succeed] [no-webhooks]` reaches Succeeded live, with image and charge, no reload; siblings keep webhooks | `live-status.spec.ts` in `make e2e`, both projects; browser pass below (6 runs with a sibling `[succeed]`) | Pass |

## Edge cases probed

- **Normal sibling finishes first, and its webhooks still arrive.** In all six browser runs, the plain `[succeed]` card reached Succeeded at about 9.3 to 9.4 s, before the `[no-webhooks]` card (9.8 to 13.1 s). The DB shows `generation.processing` and `generation.succeeded` webhook events for its generations.
- **Failed through the reconciler alone.** `[fail] [no-webhooks]` is failed by a poll with no webhook, with the hold released once and no charge (6 runs, 6 DB rows).
- **Duplicate and out-of-order delivery.** Covered by the AC 8 and AC 11 tests above. No browser case was produced.
- **Concurrent runs.** The six browser runs in each group ran in parallel (fully parallel Playwright), each with its own user. All passed.

## Visual verification

Screens touched: History (`/generations`), live and signed in as fresh E2E users. Widths 375, 768 and 1440 in both the Chromium desktop and Pixel 7 mobile projects. The viewport was set inside the test.

**Measured, all six runs:**
- Horizontal scroll (`documentElement.scrollWidth` vs `clientWidth`): 375/375, 768/768 and 1440/1440 at both the live and final checkpoints. The card box check (`expectInsideContentBox`) passed at every width.
- Console and page errors: none. The fixture fails any test with an error, and all 12 runs passed.
- No full reload: the marker survived each run.
- The `[no-webhooks]` card had its image loaded (`naturalWidth > 0`), and its charge and estimate sat on the card's line.

**Live transitions, `[succeed] [no-webhooks] a red fox`, Low, Square:**

| Run | Live state seen | Submit to Succeeded (browser) | Charge shown | Estimate shown |
| --- | --- | --- | --- | --- |
| desktop 375 | Queued | 13.1 s | $0.0206 | $0.0140 – $0.0219 |
| desktop 768 | Queued | 13.0 s | $0.0181 | $0.0140 – $0.0219 |
| desktop 1440 | Queued | 9.8 s | $0.0162 | $0.0140 – $0.0219 |
| mobile 375 | Queued | 12.9 s | $0.0208 | $0.0140 – $0.0219 |
| mobile 768 | Queued | 12.9 s | $0.0175 | $0.0140 – $0.0219 |
| mobile 1440 | Queued | 12.8 s | $0.0177 | $0.0140 – $0.0219 |

Browser times are read through History's 3 s poll, so each is accurate to about 3 s. From the DB, `created_at` to `last_provider_update_at` is 9 to 12 s. The dev thresholds explain that: the row is polled at 5 s since its last update (`QUEUED_AFTER`), and the scheduler runs every 5 s (`EVERY`), then History polls. The reconciler found the row already Succeeded at its first poll.

**Failed-poll runs, `[fail] [no-webhooks] a red fox`:** Queued to Failed in 9.5 to 12.5 s, with the `[fail]` copy and "Not charged" beside the estimate, at all three widths on both projects.

**Browser-checked states:** Queued (live), Succeeded through the reconciler (live, no reload), Failed through the reconciler (live, no reload), and a sibling `[succeed]` finishing first. Image, charge and estimate on the card.

**Not seen in the browser:** `Processing` on the `[no-webhooks]` card. It went straight from Queued to Succeeded, because the first poll found the fake already succeeded. The story's mockup shows Processing. This is consistent with AC 10 and is not a defect. The `Processing` state for a polled row is covered by the AC 10 tests.

**Test-checked only (not producible from the browser at dev settings):** the stuck notice (15 min default), `lost` (24 h), `not_found`, re-dispatch, the never-give-up rule, and the reserve under live traffic.

**Screenshots (scratch, not in the repo):** `.../PE-014/shots/`
- `<project>-<width>-1-live-queued.png`
- `<project>-<width>-2-final.png`
- `<project>-<width>-3-dropped-card.png`
- `<project>-<width>-failpoll.png`
- Measurements: `<project>-<width>.json` and `<project>-<width>-failpoll.json`

## Ledger and database read-back

Read-only SQL, run through `psql` in the `prompt-edit-postgres` container inside a `default_transaction_read_only` session. The Boost `database-query` tool could not reach the `postgres` host from the host shell.

- **`[no-webhooks]` generations:** 18 in total. Six come from this pass; the other 12 were created before it started. For every one of the 18:
  - exactly three ledger rows: one `hold` of -21,881,250, one `release` of +21,881,250, and one `charge`;
  - `user_charge_nanodollars` = ceil(`provider_cost_nanodollars` × 125 / 100), computed in SQL, for all 18 rows;
  - `provider_cost` and `user_charge` each stored once, on the generation row;
  - `last_provider_update_at` and `reconcile_recheck_at` filled, and `completed_at` set;
  - `pixelforge_webhook_events` count = 0. `pixelforge_webhook_events` records received webhooks, and the fake sends nothing for a dropped generation, so zero means none arrived. There is no other delivery table to check.
- **Sibling `[succeed] a red fox` (this pass):** `generation.processing` and `generation.succeeded` events stored, each once. Three ledger rows each. The hold is 21,850,000, the release 21,850,000, and the charge matches the provider cost × 1.25.
- **Failed-poll generations (6):** `status` = `failed`, `error_code` = `generation_failed`, `provider_cost` = 0, `user_charge` = 0, `provider_id` set. Two ledger rows (hold -21,875,000, release +21,875,000), no charge, zero webhook events.
- **Estimate note:** the `[no-webhooks]` prompt has 33 code points, so its exact estimate is 14,043,750 to 21,881,250 n$. The AC 7 numbers are for the 19-code-point `[succeed] a red fox`. Both display as $0.0140 – $0.0219.
- **Fake usage:** the fake seeds usage from the idempotency key, so charges differ by run. The AC 7 exact numbers are asserted in `ReconcileSettlementTest`.

## Notes (not blocking)

1. The `[no-webhooks]` card never shows `Processing` in the browser (see above). The mockup in the story shows it, so the story's text could be read as expecting it. The AC text does not require it.
2. The stuck, `lost`, `not_found` and re-dispatch states need a short dev threshold to be seen in real time. The story does not ask for those values in `.env.example`, so they are test-checked only.

## Data created

- This pass created 12 E2E users (6 `[no-webhooks]` runs and 6 failed-poll runs), 18 generations and 48 ledger rows. All are append-only, so nothing was deleted.
- The DB is at 40 users, 42 generations and 114 ledger rows since 13:35 UTC. Subtracting this pass gives 28 users, 24 generations and 66 ledger rows from `make e2e`. That subtraction assumes nothing else wrote to the dev DB in that window.
- `make check` runs against the test database.
