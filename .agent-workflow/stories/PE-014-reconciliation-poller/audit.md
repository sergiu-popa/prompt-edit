# Audit — PE-014-reconciliation-poller

**Verdict: PASS**

Run on branch `pe-014-reconciliation-poller` from `5655e3f` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (round 2 APPROVE) and `qa.md` (QA PASSED) present.

## Scope

I read the whole `[PE-014]` diff (`origin/main...5655e3f`, 82 files) against `story.md` AC 1–22, `plan.md`, both review rounds, QA and D-49 to D-56. That covers:
- `ReconcileGenerationsAction`, `PollGenerationAction`, `RedispatchGenerationAction`, `GiveUpUnconfirmedGenerationAction`, `ReconcilePolicy`, `PixelforgeRequestBudget`, `PixelforgeCreateLock`, and the changes to `SubmitGenerationAction`, `SubmitGenerationJob`, `SubmitBookkeeping`, `TransitionGenerationAction`, `Generation`, `GenerationQueryBuilder` and `GenerationResource`;
- the contract's `retrieveGeneration()` in the HTTP, fake and throttled clients, and the fake's `[no-webhooks]` and drop switch;
- the command, `routes/console.php`, `config/pixelforge.php`, `.env.example`, `docker-compose.yml`, `phpunit.xml` and the seven migrations;
- `HistoryItem.vue`, `types/generation.ts`, the E2E helpers and spec, and every new or changed PHPUnit, Vitest and Playwright test.

I also read `SubmitRetryPolicy`, `StopSubmittingGenerationAction` and `SubmitAttemptOutcome` (PE-013), and Laravel 13's `Cache\DatabaseLock::acquire` (`insertOrIgnore`, then an update of an expired or own row) in `vendor/`. I checked `.env.production.example`, `deploy.php` and README § Production.

Where the auditor checklist mentions Livewire, the Inertia/Vue rules in `.agent-workflow/CLAUDE.md` apply instead. PR size is not a finding.

## What was verified

- **Money exactly once, under every interleaving (AC 7–11, 14, 20).**
  - Every path goes through `TransitionGenerationAction`, which re-reads the row `FOR UPDATE` inside its transaction. The poll, the webhook, the submit job's create or replay, and the give-up all hand it a possibly stale instance, and it classifies against the locked row. A second release or charge is refused by `unique(generation_id, type)` with `ON CONFLICT DO NOTHING` (PE-007), not by a status check.
  - Webhook then poll, poll then webhook, and a webhook inside the poll's request each give one release and one charge (`ReconcileSettlementTest`). A `[duplicate]` succeeded webhook after a poll settled writes nothing. A re-dispatch replay (`202`, `Idempotent-Replayed: true`, succeeded) gives one hold, one release and one charge (`ReconcileRedispatchTest`).
  - `lost` is free: the ledger is hold and release only, costs 0. A late success on a `lost` row goes through the existing D-09 `recordProviderCostOnly` path: provider cost 15,125,000 n$, user charge 0, status Failed, ledger unchanged (`ReconcileGiveUpTest`).
- **Give-up only for unconfirmed rows (AC 20, 21, D-49).**
  - `awaitingRedispatch()` requires `status = pending` and `provider_id is null`, and the give-up is only reached from that selection. `PollGenerationAction` never fails a row; a confirmed row stays polled at the slowed cadence. Its `not_found` streak (`reconcile_not_found_since`) is cleared by any snapshot, and one error log is written once the streak is past 24 h (`reconcile_not_found_reported_at`).
  - The give-up takes the create lock without waiting, so no re-dispatched create is in flight while it fails the row; a row confirmed after its selection is refused by the transition and only an info line is written.
- **Re-dispatch (AC 12–15, D-52, D-53).**
  - It reuses `SubmitGenerationJob` with the same ULID, so the same row, `Idempotency-Key`, `metadata.generation_id` and bytes (the body is rebuilt from stored columns). Pixelforge keys never expire (requirements §2.3), so a re-dispatch up to the 24 h give-up can always replay.
  - The owed run skips only the start-of-run window check, and only while no attempt started at or after the re-dispatch instant. After it, `SubmitRetryPolicy::decide()` and `StopSubmittingGenerationAction` are unchanged, so the ambiguity latch holds: a latched row is never failed free, and a handed-over row's stop is a no-op. I added tests for both properties (#1, #2).
  - **One create in flight.** `PixelforgeCreateLock` is `pixelforge-create:{ulid}` on the pinned `database` store (`cache_locks`), shared across processes whatever `CACHE_STORE` says. It is taken before the row is re-read and released in `finally`. Its TTL is 90 s, past the job's 60 s timeout, so a run killed at its timeout (no `finally`) still holds the next off; the queue re-offers that job only after `retry_after` 90 s. A held lock makes the run `release(5)` and record nothing, so it never touches the window, the latch or the owed run. `release()` counts as an attempt, but `retryUntil` (window + 600 s from dispatch) overrides `--tries`, and the lock can hold a run off for at most 90 s. `insertOrIgnore` means a held lock never aborts a surrounding Postgres transaction.
- **Selection and cadence (AC 5, 6, 16, D-50, D-51).**
  - Staleness reads only `last_provider_update_at`, never `updated_at` (`test_stale_at_pixelforge_never_reads_updated_at`). Only `snapshotAttributes()` stamps it, so ignored, refused and conflicting snapshots, local failures and the D-09 write leave it alone. The `reconcile_*` columns go through a column-only UPDATE that never touches `updated_at`.
  - The thresholds are strict: queued 2 min (1:50 skipped, 2:10 polled), processing 3 min (2:50 skipped, 3:10 polled).
  - `reconcile_recheck_at` = now + clamp(age ÷ 4, 1 min, 15 min), written before the request. A 2 h row is polled at most 4 times an hour (`ReconcilePollTest`).
  - At most 20 rows a run, polls oldest last update first, then unsubmitted rows oldest created first. All three selections read a partial index, asserted by EXPLAIN with `enable_seqscan = off` (`generations_reconcile_poll_index`, `generations_pending_index`, `generations_stuck_unwarned_index`).
- **Budget (AC 1, 17).** Every retrieve takes one unit through `ThrottledPixelforgeClient` and the same `PixelforgeRequestBudget` key that submits use. A poll is sent only while more than 10 units remain (12 left → 2 polls, the story's example). A spent budget or a 429 stops the run's polls and fails nothing: the row only waits for its next check.
- **Scheduler (AC 4, D-54).** `app:reconcile-generations` runs `everyMinute()` with `withoutOverlapping(expiresAt: 15)` and `onOneServer()`. Their mutex uses the default cache store, which is `database` in every environment (D-20, `config/cache.php` default, `.env.example`). Each row is isolated by a `Throwable` catch that logs the step, the ULID, the provider id and the exception class, and the run goes on. The sub-minute frequency comes only from `PIXELFORGE_RECONCILE_EVERY`, which only `.env.example` and the compose dev anchor set. Production's Hestia cron already runs `schedule:run` every minute, so it needs no change.
- **Dev and E2E values stay out of production.**
  - Every `config/pixelforge.php` default is the production value (120, 180, 60, 900, 20, 10, 60, 300, 86,400, 900, drop switch off). The short values (5, 5, 2, 5) are only in `.env.example` and the `x-pixelforge-dev-bounds` anchor.
  - Production's `shared/.env` comes from `.env.production.example`, which still has no `PIXELFORGE_*` name (`ProductionEnvTemplateTest`). **Production's `.env` needs no new line.**
  - Each of the 11 new env names is cleared in `phpunit.xml` (`<env force>` and `<server>`) and listed in `.env.example` in the same commit that first reads it in config (`ba25c11`, `c5f1eb7`, `5297a15`, `e62f8c3`, `73fbd1a`, `ede4716`, `f13a40a`). I checked this per commit with `git show`. I did not re-run the suite on each intermediate commit; the engineer ran `make check` per task, and review checked the commit order.
- **Migrations.** All seven are additive: nullable `timestampTz` columns, one boolean defaulted to false on the fake's table, and three partial indexes. The backfill sets `last_provider_update_at = updated_at` on confirmed rows only, through the query builder, so `updated_at` is unchanged. On deploy, existing non-terminal rows become eligible as designed: confirmed ones are polled, unconfirmed ones are re-dispatched, or given up as `lost` and free if older than 24 h.
- **No secrets in logs.** Every new log line carries only ids, statuses, outcomes, instants, `context()` (`error_code`, `status`, `retry_after_seconds`) or an exception class. None carries a key, a body, a prompt or an exception message.
- **UI (AC 19, 20).** The stuck note comes from `ReconcilePolicy::STUCK_NOTE` through `status_note`, and `lost` from `GenerationFailureCode`, the one failure-copy mapping (PE-012). Both are rendered by text interpolation in the existing `[data-status-note]` and `[data-failure-message]` paragraphs (`wrap-break-word`); there is no `v-html`. Dev cannot produce either state in real time, so I ran a temporary Playwright spec that rewrote the server's partial-reload JSON to carry the two strings, on a `[slow]` processing card and a `[fail]` card, at 375 px in both projects. Both passed: no horizontal scroll, every element inside the card's content box, "Not charged" beside the estimate. The spec is deleted. Screenshots: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-014/shots-audit/`.
- **Vue layer.** There is no `any` in `resources/js`, `tests/js` or `tests/e2e`. No page changed, and `HistoryItem` only gained a docblock. The E2E timeout is derived from the same env values the app reads.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | D-52 says duplicate re-dispatches collapse into one attempt, but only the same job run twice was tested. Two reconciler runs a minute apart, with a busy worker that runs both jobs afterwards, were not. | `tests/Feature/Jobs/SubmitGenerationRedispatchTest.php` | Suggestion | Fixed | New `test_two_redispatches_queued_before_either_runs_send_one_create`: the first job is the owed run, the second finds an attempt since its own instant and sends nothing; one hold only, still handed over (`f815678`). |
| 2 | No test showed that the owed run keeps PE-013's latch for a "nothing created" answer. Only the never-latched 503 (fails free) and the latched timeout were covered. | `tests/Feature/Jobs/SubmitGenerationRedispatchTest.php` | Suggestion | Fixed | New `test_a_latched_row_whose_owed_run_is_overloaded_stays_handed_over_with_its_hold`: a latched row's owed run answered `503 overloaded` stays pending and handed over, with no failure reason and its hold only (`f815678`). |
| 3 | A never-latched row whose owed run finds our own budget spent records `budget_exhausted`; past its window, PE-013's stop then fails it free as `provider_busy`, so no create reached Pixelforge on that re-dispatch (AC 13's "at least one attempt"). | `app/Domain/Generation/Actions/SubmitGenerationAction.php:111` | Suggestion | Skipped | Money-safe: an unlatched row has nothing at Pixelforge, and PE-013 applies the same window-end rule to its own jobs (AC 17, `windowEndReason()`). Plan "What an attempt is" records it. It needs a dead job (the queue normally re-offers it until `retryUntil`, whose `failed()` stops the row itself), a window already over and a spent budget at once. Changing it would alter PE-013's decision, which this story must not weaken. |
| 4 | A poll stopped by a spent budget or a 429 has already written its `reconcile_recheck_at`, so that row waits one recheck without having been polled. | `app/Domain/Generation/Actions/PollGenerationAction.php:49` | Suggestion | Skipped | Deliberate (D-50): writing first keeps one bad row from draining the budget. The delay is one recheck interval (1–15 min), the row is not failed, and the next run polls it. |
| 5 | The scheduler's `withoutOverlapping` and `onOneServer` mutex follows `CACHE_STORE`, unlike the budget and the create lock, which are pinned. | `routes/console.php:37` | Suggestion | Skipped | `CACHE_STORE` is `database` in every environment (D-20, config default, `.env.example`), as the existing backup entry already relies on. `Schedule::useCache()` would pin every event, a wider change than this story. |
| 6 | `GenerationResource` resolves `ReconcilePolicy` once per item (review S3). | `app/Http/Resources/GenerationResource.php:84` | Suggestion | Skipped | A page is at most 20 items, each resolution reads cached config, and a singleton would break tests that change config at runtime. Review round 2 accepted it. |
| 7 | The per-row error log names the exception's class but not its message. | `app/Domain/Generation/Actions/ReconcileGenerationsAction.php:157` | Suggestion | Skipped | Deliberate: a `QueryException` message can carry bound values such as a prompt. The class, step, ULID and provider id are enough to find the row, and the cadence retries it. |

No Critical or Warning issues were found.

## Checks after fixes

Run on `f815678`, after the fix commit:

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `make check` | Exit 0. Pint PASS (277 files). Larastan no errors. PHPUnit 1,656 passed (36,731 assertions), 2 more than QA's 1,654. Vitest 498 passed in 31 files. |
| E2E, desktop and mobile | `make e2e` | Exit 0. 42 passed, 2 skipped (the existing touch-only and mouse-only rows of `generate.spec.ts`), including AC 22 on both projects. |
| Stuck and `lost` copy at 375 px | temporary spec, `make e2e filter="audit:"` | 2 of 2 passed (mobile, desktop). The spec is deleted. |

Only a test file changed, and no job, action, command or config code did, so the worker needed no restart.

## Commits

- `f815678` [PE-014] Cover duplicate re-dispatches and the latch on the owed run
- `[PE-014] Record audit` (this file and the story status)
