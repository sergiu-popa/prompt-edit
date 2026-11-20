# Audit — PE-013-provider-limits-retries-and-throttle

**Verdict: PASS**

Run on branch `pe-013-provider-limits-retries-and-throttle` from `b13dcf7` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (round 1 APPROVE) and `qa.md` (QA PASSED) present.

## Scope

I read the whole `[PE-013]` diff (`main...b13dcf7`, 63 files) against `story.md` AC 1–23, `plan.md`, the review, QA and D-44 to D-48. That covers:
- `ThrottledPixelforgeClient`, `RateBudgetExhaustedException`, the 429 fallback in `HttpPixelforgeClient` and `AppServiceProvider`'s wrapping;
- `SubmitBookkeeping`, `SubmitRetryPolicy`, `SubmitDecision`, `SubmitAttemptOutcome`, `SubmitRetryNote`, `SubmitGenerationAction`, `StopSubmittingGenerationAction` and `SubmitGenerationJob`;
- the migration, the model casts, `GenerationResource`, `config/pixelforge.php`, `.env.example`, `docker-compose.yml` and `phpunit.xml`;
- `HistoryItem.vue`, the TS type, and every new or changed PHPUnit, Vitest and Playwright test.

I also read Laravel 13's `Queue\Worker` (`markJobAsFailedIfAlreadyExceedsMaxAttempts`, `markJobAsFailedIfWillExceedMaxAttempts`, `registerTimeoutHandler`, `timeoutForJob`), `Cache\RateLimiter::increment` and `Cache\DatabaseStore` (`add`, `many`, `incrementOrDecrement`) in `vendor/`. I checked `.env.production.example`, its test, `deploy.php`, the supervisor worker and README § Production, and PE-014's story for what the reconciler reads.

Where the auditor checklist mentions Livewire, the Inertia/Vue rules in `.agent-workflow/CLAUDE.md` apply instead. PR size is not a finding (`.agent-workflow/CLAUDE.md` § Git hygiene).

## What was verified

- **Money exactly once (AC 15, 16).**
  - Every local failure goes through `TransitionGenerationAction`: `failLocally` for a failure with a response in hand (deadline, daily limit, 401, definitive rejection), and `StopSubmittingGenerationAction` at the window's end. The transition writes cost 0 and charge 0, then `SettleGenerationCreditsAction` writes one release and no charge.
  - A second release is impossible by the database, not only by status. `credit_ledger_entries` has `unique(generation_id, type)`, and the settlement inserts with `ON CONFLICT DO NOTHING`, so a racing second path is ignored without aborting the transaction, and it is logged. PE-007's `test_duplicates_left_by_a_bypassed_status_guard_are_ignored_and_logged` proves this with the guard bypassed. PE-013's `test_a_second_stop_after_a_free_failure_writes_nothing` covers the status side.
  - The AC 15 numbers (hold −86,318,750, release +86,318,750, net 0) and the AC 16 ledger (−86,287,500 / +86,287,500 / −78,468,750, with 62,775,000 and 78,468,750 stored) are asserted exactly.
- **The PE-009 invariant (AC 12, 13, 23).**
  - Each run's start is recorded with a null outcome before the budget and the request. A job that dies mid-request therefore leaves an attempt with no outcome. `isAmbiguous()` reads that as latched, and the next `startAttempt` writes the latch at that attempt's start.
  - The latch is a column (`submit_ambiguous_at`) that is never cleared, so it survives a reload of the row. `SubmitBookkeepingTest` and the "finds an attempt with no outcome" rows cover it.
  - When latched, the daily limit, the concurrency max wait, the overload deadline and the 401 limit all retry instead, and the window's end hands the row over `pending` with its hold.
  - `submit_handed_over_at` is set only by `StopSubmittingGenerationAction`, and every caller ends the job after it: the action returns null so `handle()` does not release, or `failed()` runs it. It is never set while the job is still retrying.
  - An unexpected exception (from the cache, say) leaves no outcome, so the next run treats it as ambiguous. That is the safe side, and the plan states it.
- **Byte-identical retries (AC 9, 18, 20).** Every run re-reads the row and rebuilds `CreateGenerationPayloadData` from stored columns. `Idempotency-Key` and `metadata.generation_id` are both the ULID. `SubmitGenerationRetriesTest` compares method, URL, key, metadata and body across `503` → timeout → `202`. A terminal row or a stored `provider_id` returns before anything is sent. A replay goes through the forward-only transition, so `processing` or `succeeded` never goes back.
- **Timing (AC 3, 4, 5, 9, 14, 17, 19).**
  - Every release delay is at least `Retry-After` for rate limits, concurrency limits and overloads, and at least 1 s. With no `Retry-After`, the defaults are all at most 60 s: 60, 5, 2–30, 5–60 (ambiguous), 20 or 60 (401), 60 (not configured).
  - The window is measured from `submit_first_attempted_at`. A retry whose next attempt would start at or after the window's end is a stop instead.
  - `--tries=3` cannot end the job early. This holds for the supervisor worker (`queue:work --sleep=3 --tries=3 --max-time=3600`) and for the production unit (`queue:work database --sleep=3 --tries=3 --max-time=3600 --memory=128`). The job defines `retryUntil()`, and the payload's `maxTries` is null.
    - In Laravel 13, `markJobAsFailedIfAlreadyExceedsMaxAttempts` returns early while `now <= retryUntil`, and checks the tries only when there is no `retryUntil`. `markJobAsFailedIfWillExceedMaxAttempts` and the timeout handler do the same.
    - `SubmitGenerationWorkerTest` drives the real `queue:work database --once --tries=3` through five releases.
  - **Production as deployed:** HTTP 30 s (connect 5 s inside it) < job 60 s < `retry_after` 90 s.
    - The job's `$timeout` goes into the payload, and `timeoutForJob` prefers it over the unit's default `--timeout` of 60.
    - `.env.production.example` sets neither `PIXELFORGE_REQUEST_TIMEOUT` nor `DB_QUEUE_RETRY_AFTER`, and `ProductionEnvTemplateTest` pins its exact list of names.
    - `deploy.php` runs `queue:restart` through `artisan:reload`, so the worker picks up the new job class.
    - No droplet change is needed.
  - Before this audit the order held only for the default request timeout; see #1.
- **Shared budget (AC 1, 2, D-44).**
  - `AppServiceProvider` wraps whichever driver it builds, so every contract call takes a unit. Today that call is `createGeneration`; `isConfigured()` takes none.
  - The store is pinned to `database`, whatever `CACHE_STORE` says. `RateLimiter::increment` calls `DatabaseStore::incrementOrDecrement`, a `SELECT … FOR UPDATE` and an `UPDATE` in one transaction. On Postgres, two workers can therefore never both take the last unit. An expired counter is deleted by `many()` before `add()` re-creates it.
  - `RateBudgetExhaustedException` is only ever a retry after `availableIn` (at least 1 s), so the budget never fails a generation on its own; only the window's end does (`provider_busy`).
- **Dev and E2E values stay out of production (D-48).**
  - The config defaults are the production values: 50 a minute, a 120 s overload deadline, a 600 s concurrency wait and a 1,800 s window (`test_empty_submit_bounds_mean_the_production_defaults`, `test_empty_values_mean_the_defaults`).
  - The 600 a minute and the 15 s deadline come only from `.env.example` and the compose anchor. Neither reaches the droplet: its `shared/.env` comes from `.env.production.example`, which has no `PIXELFORGE_*` name (`test_it_has_no_pixelforge_variable_before_pe_015`).
  - `phpunit.xml` clears both names in `$_ENV` and `$_SERVER`, so the suite runs with production's bounds.
  - **The production `.env` needs no new line.**
- **Secrets (AC 14).**
  - The critical entry's context is exactly `generation_id`, `error_code` and `status`.
  - Every other line on the submit path logs only `ReportsPixelforgeContext::context()`: `error_code`, `status` and `retry_after_seconds`, or the ULID and times. `failed()` logs the exception's class, never its message or its previous exception.
  - Before this audit, only the critical entry was checked; see #2.
- **Migration.** It adds nine columns, eight nullable and one `smallInteger` defaulted to 0. On Postgres 11+ that is a metadata-only change, safe on production rows, which read as never attempted. `down()` drops exactly those columns.
- **The retry note (QA note 1, AC 6).** Decided: no code change; see #3.
- **UI (AC 21, 22).**
  - `GenerationFailureCode` is the one failure-copy mapping. Its four new cases match the story's table, and the page renders `failure_message` and `status_note` with text interpolation; there is no `v-html`.
  - Every new failure shows "Not charged" beside its estimate, which stays visible (`HistoryItem.test.ts`, E2E).
  - The note reuses the card's `text-sm` and `wrap-break-word` from PE-012's polish. It adds no `[data-tone]`, so the badge stays a neutral "Queued".
  - The AC 22 spec asserts no horizontal scroll at 375 px, and QA measured 0 px at 375, 768 and 1440 px in both projects.
- **Vue layer.** There is no `any` in `resources/js`, `tests/js` or `tests/e2e`. No page changed. `HistoryItem` only renders a prop, with no logic to move into a composable or lib.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | AC 19's order (request timeout < job timeout) held only for the default. `PIXELFORGE_REQUEST_TIMEOUT` accepted any positive integer, while the job timeout is a fixed 60, so setting 60 or more let the worker kill the job mid-request. `SubmitGenerationDispatchTest` checks only the defaults (review S4). | `config/pixelforge.php:44` | Warning | Fixed | The job timeout is now one `$submitJobTimeoutSeconds` in the config file, and the request timeout's `max_range` is one below it. A value at or above it means the default of 30, the existing rule for an invalid timeout. New `PixelforgeConfigTest` rows: 60, 61 and 600 give 30, and 59 applies and stays below the job timeout. D-33 notes the cap (`4edfb35`). |
| 2 | The no-secrets check of AC 14 covered only the critical entry. A key or `Authorization` value in any other line of the 401 path (the retry warnings, the free-failure warning) would have gone unnoticed. | `tests/Feature/Jobs/SubmitGenerationLimitsTest.php:215` | Suggestion | Fixed | Both 401 tests now also check every logged line, at any level, with its context, for the sentinel key, `Bearer` and `Authorization`. A temporary mutation that added the key to the retry warning's context made the test fail (`5ddbee5`). |
| 3 | QA note 1: `submit_retry_note` stays `retrying` on a row that has failed free, and on one Pixelforge accepted. | `app/Http/Resources/GenerationResource.php:82` | Suggestion | Skipped | No code change. AC 6's marker is what History shows, and the resource sends the note only while the row is `pending`. A 202, a replay, a webhook or a local failure moves the row off `pending` inside the transition's transaction, so the marker goes in the same commit (`test_the_free_slot_marker_clears_once_pixelforge_accepts_it`, and the stale-note rows of `GenerationHistoryStatusTest`). The column itself cannot be cleared on every terminal transition without writing bookkeeping inside the transition action, which AC 23 forbids. Clearing it only on the submit path's own transitions would make its meaning depend on which path ended the row. PE-014's reconciler reads the hand-off flag, `provider_id` and the attempt times (its AC 12), never the note, so a stale note cannot mislead it. D-45 now says the column is never cleared and means nothing off `pending` (`d728f50`). |
| 4 | The overload deadline and the concurrency max wait run from the first 503 or concurrency 429 of the whole window, not of the current streak (review S3). | `app/Domain/Generation/ValueObjects/SubmitRetryPolicy.php:162` | Suggestion | Fixed | This is the story's wording ("after its first 503", "after its first concurrency 429"), so the behaviour stays. D-47 now states it for PE-014 and later readers (`d728f50`). |
| 5 | `SubmitDecision::isStop()` is used only by tests (review S1). | `app/Domain/Generation/DataTransferObjects/SubmitDecision.php:42` | Suggestion | Skipped | It names the third case of the DTO for the policy's tests and for readers. `afterFailure` branches on the two fields because it needs their values. Removing it would make the policy tests assert two nulls instead of the intent. |
| 6 | The budget is spent by a call that cannot be sent: with no key, `ThrottledPixelforgeClient` takes a unit before the driver throws `PixelforgeNotConfiguredException` (review S2). | `app/Support/Pixelforge/ThrottledPixelforgeClient.php:40` | Suggestion | Skipped | It is harmless: when nothing is configured, nothing can be sent, so no Pixelforge call competes for the unit. The docblock and D-44 say "every call through the contract", which is exactly what happens. Keeping the decorator unconditional means no driver's `isConfigured()` can ever let a call skip the budget. |
| 7 | Two commits were red until a later fix-up (review W1: `ad2a4dd` before `13c289e`, `0550679` before `88c95d1`). | branch history | Warning | Skipped | Fixing it needs a history rewrite, which the pipeline forbids. The review acknowledged it, each fix-up names what it fixes, and HEAD is green. The user can squash the pairs at merge. Each audit commit passed Pint, Larastan and its affected tests before it was made, and the full `make check` passed on top of all three. |

No Critical issues were found. Warning #1 is fixed. Warning #7 is acknowledged, because it cannot be fixed without rewriting history.

## Checks after fixes

The worker was restarted (`make restart-worker`) after the config change and before E2E.

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `make check` | Exit 0. Pint passes on 249 files, and Larastan reports no errors. PHPUnit: 1,435 passed (35,796 assertions). ESLint, Prettier and vue-tsc are clean. Vitest: 496 passed in 31 files. |
| E2E, desktop and mobile | `make e2e` | Exit 0. 40 passed and 2 skipped: the designed touch-only and mouse-only rows. The AC 22 `[overloaded]` test passed on both projects. |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-013/audit-check.log` and `.../PE-013/audit-e2e.log`. No real Pixelforge request was made.
