# QA — PE-013-provider-limits-retries-and-throttle

**Verdict: QA PASSED**

Run on branch `pe-013-provider-limits-retries-and-throttle` at `199c7a3`. Prerequisites checked: story status `qa-ready`, review round 1 verdict `APPROVE`, no Critical review items open. The worker was restarted (`make restart-worker`) before the run, so it runs this branch's code. The app and worker were up, and the frontend was built (`npm run build`).

Scratch root: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-013/`

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Lint, Larastan, PHPUnit, JS lint/format/types, Vitest | `make check` | Exit 0. Pint PASS (249 files). Larastan no errors. PHPUnit 1,431 passed (35,758 assertions). ESLint and Prettier clean. vue-tsc clean. Vitest 496 passed in 31 files. |
| Existing E2E (desktop + mobile) | `make e2e` (build + Playwright) | Exit 0. 40 passed, 2 skipped. The skips are the designed touch-only and mouse-only rows, one per project. Includes the AC 22 `[overloaded]` spec, which passed on both projects. |
| Temporary browser pass (desktop + mobile, 375 / 768 / 1440) | `docker compose run --rm playwright npx playwright test tests/e2e/qa-shots.spec.ts` | 12 of 12 passed (6 live-transition runs, 6 reload runs). Spec deleted from the repo after the run. |

Logs: `.../PE-013/check.log`, `.../PE-013/e2e-full.log`, `.../PE-013/qa-shots.log`.

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 one account-wide budget, shared across job runs | `PixelforgeConfigTest` ("the rate budget is always counted in the database store"); `ThrottledPixelforgeClientTest` ("calls within the budget reach the driver") | Pass |
| 2 51st call not sent, submit released up to 60 s, stays Queued | `ThrottledPixelforgeClientTest` ("the call past the budget sends nothing and waits for the window", "budget waits end only with the window"); `test_a_spent_budget_sends_nothing_and_waits_for_it_to_free_up` (submit job) | Pass |
| 3 429 `rate_limit_exceeded` + `Retry-After` waits, Queued, persisted note | `test_it_waits_for_retry_after_or_the_outcomes_default`, `test_the_note_moves_with_each_wait_and_survives_an_acceptance`; browser: retry note persists across reload (below) | Pass (429 itself test-checked only) |
| 4 missing or unparsable `Retry-After` → ≤ 60 s; unknown 429 code treated as rate limit | `test_a_retry_after_header_is_read_as_whole_seconds_or_not_at_all`, `test_a_429_without_a_documented_envelope_is_a_rate_limit` | Pass |
| 5 429 `concurrency_limit_exceeded` waits ≥ 5 s, "Waiting for a free slot" marker, survives reload | `test_a_concurrency_limit_waits_for_a_free_slot`, `SubmitGenerationLimitsTest` (marker copy); `HistoryItem.test.ts`; `GenerationHistoryStatusTest` (`waiting for a slot`) | Pass (browser: not producible with fake) |
| 6 marker cleared on advance or terminal | `test_the_free_slot_marker_clears_once_pixelforge_accepts_it` | Pass |
| 7 concurrency wait over max → `provider_busy`, free | `test_a_slot_that_never_comes_fails_it_free_after_the_max_wait`, `test_a_latched_generation_keeps_waiting_for_a_slot_past_the_max_wait` | Pass |
| 8 `daily_limit_exceeded` → failed at once, free, hold released, copy | `test_the_daily_limit_fails_it_free_at_once`, `test_a_daily_limit_fails_free_at_once`; copy in `GenerationHistoryStatusTest`, `HistoryItem.test.ts` | Pass |
| 9 `503 overloaded` honours `Retry-After`, same key, byte-identical body | `test_an_overload_without_retry_after_waits_the_documented_2_s`, `test_a_longer_retry_after_wins_over_the_backoff`, `test_two_attempts_send_byte_identical_bodies_under_the_same_key` | Pass |
| 10 `503` then `202`: two creates, one `provider_id`, applied once; replay and webhook ordering kept | `test_an_overload_then_an_acceptance_stores_one_provider_id_and_only_the_hold`, `test_an_overload_then_a_replay_that_already_succeeded_is_charged_once` | Pass |
| 11 overload past `PIXELFORGE_OVERLOADED_DEADLINE` → `provider_overloaded`, free | `test_an_overload_at_its_deadline_fails_free`, `test_an_overload_past_its_deadline_fails_free_with_one_release`; browser (below) | Pass |
| 12 ambiguous outcomes retried with same key, never failed locally | `test_an_ambiguous_answer_latches_and_is_retried_but_never_failed`, `test_a_timeout_latches_the_generation_as_ambiguous`, `test_a_response_cut_off_after_its_status_line_is_ambiguous`, `test_a_2xx_that_is_not_a_generation_object_is_ambiguous`, `test_a_transport_failure_is_ambiguous` | Pass |
| 13 ambiguity latch blocks every local failure; handed over when the window ends | `test_an_ambiguous_outcome_waits_as_long_as_it_has_been_ambiguous`, `test_a_latched_generation_is_handed_over_at_its_windows_end`, `test_a_latched_daily_limit_checks_back_a_minute_later`, `test_a_latched_overload_past_its_deadline_keeps_retrying` | Pass |
| 14 401 retried ≤ 3 in 60 s, then free `service_unavailable`; one critical log, no secrets | `test_a_refused_key_is_tried_three_times_then_fails_free_with_one_alert`, `test_a_latched_generation_whose_key_is_refused_keeps_trying_with_one_alert`, `test_no_critical_log_carries_the_body_when_no_secret_is_configured` | Pass |
| 15 local failures through the transition action; hold released once; no charge; costs 0; worked example 86,318,750 | `test_a_local_failure_is_free_and_releases_the_hold`, `test_an_overload_past_its_deadline_fails_free_with_one_release`; ledger check (below) | Pass |
| 16 retries never double-charge; 62,775,000 / 78,468,750; three ledger entries | `test_a_replay_that_has_moved_on_to_succeeded_is_charged_once`, `test_an_overload_then_a_replay_that_already_succeeded_is_charged_once` | Pass |
| 17 window bounds the job, not `--tries`; reason follows last outcome | `test_the_workers_tries_never_end_a_job_that_is_waiting`, `test_the_window_end_fails_it_free_by_its_last_outcome`, `test_the_window_is_measured_from_the_first_attempt` | Pass |
| 18 retries byte-identical (method, URL, key, metadata, body) | `test_two_attempts_send_byte_identical_bodies_under_the_same_key`, `test_an_overload_a_timeout_and_an_acceptance_send_byte_identical_requests` | Pass |
| 19 timeouts ordered: HTTP < job < queue `retry_after` | `test_the_timeouts_nest_below_the_queue_retry_window`, `test_the_suite_runs_with_the_production_rate_budget_and_overload_deadline` | Pass |
| 20 terminal or known `provider_id` → no call; never moves backwards | `test_a_generation_with_a_provider_id_or_a_terminal_status_is_not_sent`, `test_a_generation_that_advanced_while_its_job_waited_is_not_sent_again` | Pass |
| 21 copy under Technical Notes; "Not charged" with estimate visible | `HistoryItem.test.ts` (45 tests, incl. the copy table), `GenerationHistoryStatusTest`, `GenerationFailureCodeTest`; browser: "Not charged" beside the estimate on every failed card | Pass |
| 22 `[overloaded]` Queued → retrying → Failed, no reload, within timeout | `live-status.spec.ts` in `make e2e` (desktop and mobile); temporary pass at 375 / 768 / 1440 on both projects (below) | Pass |
| 23 bookkeeping fields (first and last attempt, latch, hand-off flag) written outside the transition action | `test_the_bookkeeping_after_an_overload_a_timeout_and_the_windows_end`, `test_a_hand_over_sets_its_time_and_the_retrying_note`, `test_a_pending_row_whose_last_attempt_was_accepted_is_handed_over`; DB read-back (below) | Pass |

## Edge cases probed

- **Overload deadline racing the final failure.** `test_an_overload_past_its_deadline_fails_free_with_one_release` plus the PE-007 unique constraint. The ledger has one release per generation (read-back below), with no second release.
- **Ambiguous attempt, then a limit.** An ambiguous generation stays non-terminal with its hold and is never failed locally (AC 13 tests). No such row appeared in the browser pass.
- **Duplicate prompts and parallel submits.** `[duplicate]` one History item and one charge (`make e2e`).
- **Concurrent submit alongside a retrying one.** A `[succeed]` submitted while the overloaded one was retrying reached Succeeded (browser, below). The shared budget did not hold it up.

## Visual verification

Screens touched: History (`/generations`), live, signed in as fresh E2E users. Measured at 375, 768 and 1440 px in both the Chromium desktop and the Pixel 7 mobile projects. The viewport was resized inside the test. 12 runs, 30 layout checks.

**Browser-checked** (fake driver, live stack):
- `[overloaded]` → Queued with "Pixelforge is busy — retrying automatically" and the estimate, then Failed with "Pixelforge was overloaded and didn't accept this request. You were not charged." and "Not charged" beside "Est. $0.0582 – $0.0863". The change happened live with no reload. All 6 live runs passed.
- Reload while retrying: the retry note and Queued status stayed after the reload, then the item turned Failed. All 6 reload runs passed.
- A `[succeed]` submitted while the overloaded one was retrying: Succeeded in 9.7 to 12.8 s, with the retry note still showing on the other card at that moment.
- Horizontal scroll (`documentElement.scrollWidth` vs `clientWidth`): 0 px overflow in all 30 checks, at all three widths in both projects.
- Console: no console or page errors. The fixture fails any test with a console error, and all 12 runs passed.

**Measured timings** (wall clock, from the spec):

| Run | Retry note shown | Submit to Failed | Reload to Failed |
| --- | --- | --- | --- |
| Live (6 runs) | 3.6 to 4.0 s | 16.6 to 20.0 s | — |
| Reload (6 runs) | — | — | 15.5 to 15.6 s |

`OVERLOADED_DEADLINE` is 15 s in the dev containers (`docker-compose.yml`, confirmed in the app and worker env). The deadline counts from the first 503. The submit to Failed figure adds the first attempt's worker sleep (up to 3 s), the last `Retry-After` wait (up to 2 s) and the History poll (3 s). The measurements fall inside that bound.

**Test-checked only** (not producible with the fake, which cannot emit 429, 401 or timeouts): `429 rate_limit_exceeded`, `429 concurrency_limit_exceeded` (marker and max wait), `429 daily_limit_exceeded`, `401 invalid_api_key`, timeouts and other 5xx, unparsable 2xx, and budget exhaustion. These are covered by PHPUnit with `Http::fake` and by the JS integration and unit tests for their copy.

**Screenshots** (scratch, not in repo), under `.../PE-013/shots/`:
- `desktop-375-live-1-retrying.png`, `desktop-375-live-2-failed.png`, `desktop-375-live-3-final.png`
- `desktop-1440-live-2-failed.png`
- `mobile-768-reload-1-after-reload.png`
- Full set: `<project>-<width>-{live,reload}-*.png` (42 files)

Measurements: `.../PE-013/shots/<project>-<width>-{live,reload}.json`.

## Ledger and database read-back

Read-only SQL on the dev database, against the 12 users this pass created.

- **AC 15 worked example** (`A lighthouse at dusk [overloaded]`, Medium, 1024×1024, no references): all 12 overloaded generations show `est_min` 58,168,750 and `est_max` 86,318,750 (`Est. $0.0582 – $0.0863`). Each has `provider_cost` 0, `user_charge` 0, `status` failed, `local_failure_reason` `provider_overloaded`, and no `provider_id`. Ledger: exactly two rows, `hold: -86,318,750` and `release: +86,318,750`, net 0, no charge. This matches the story.
- **`[succeed]` alongside** (6 generations): `status` succeeded, `provider_id` set, and charge = ceil(provider_cost × 1.25) (for example 61,000,000 → 76,250,000). Ledger: `hold`, `release`, `charge`, net = −charge. All charges fall within the estimate of 58,181,250 to 86,331,250.
- **Bookkeeping:** `submit_ambiguous_at` and `submit_handed_over_at` are null on all 12 overloaded rows (no ambiguous attempt, no hand-off). That is correct: they failed free.
- **Persistence:** `submit_retry_note` is `retrying` on the 12 overloaded rows, which is the value that backs the reload check.

## Notes (not blocking)

1. **`submit_retry_note` is not cleared on failure.** After a free failure the row keeps `submit_retry_note = 'retrying'`. The UI hides it for failed rows, so nothing shows, and no AC requires clearing this field. AC 6 only requires clearing the concurrency marker, and that is `submit_first_concurrency_limited_at`. Suggest clearing the retry note on terminal transitions in a follow-up.
2. **Dev overload deadline is 15 s**, from `docker-compose.yml`. The `.env` file has no `PIXELFORGE_OVERLOADED_DEADLINE` line, so the compose default applies. Production uses the 120 s default. The browser pass therefore exercises the short value, and the production value is covered by the config tests.
3. **Local rate budget.** `.env.example` sets `PIXELFORGE_RATE_LIMIT_PER_MINUTE=600` for E2E, so the dev and E2E runs never reached the 50 rpm budget in the browser. The AC 1 and AC 2 behaviour is covered by the feature tests.

## Data created

The temporary pass created **12 E2E users** (`e2e-desktop-*` and `e2e-mobile-*`, registered by the spec). They produced **18 generations**: 12 failed overloaded, 6 succeeded. They have **42 ledger rows** (2 per overloaded generation, 3 per succeeded). The ledger is append-only, so nothing was deleted, as the story notes. `make check` and `make e2e` created their own users, which were not counted.
