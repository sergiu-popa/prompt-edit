# Story: PE-014-reconciliation-poller

## Title
Scheduled reconciler: status and money catch up when webhooks don't arrive

## Status
`draft`

## Description
Webhooks are our primary signal, but they can fail to arrive. Pixelforge gives up on a webhook after six attempts, and our endpoint can be down or slow. A worker can also die before it submits. PE-013 also leaves ambiguous submits (timeouts, unexpected 5xx) non-terminal on purpose. Without a fallback, these generations would stay "processing" forever: the user's credit stays held and the charge is never recorded.

This story adds a scheduled reconciler that works within the rate budget. It does two things:
- It polls stale non-terminal generations that have a `provider_id` through `GET /v1/generations/{id}`, and applies each result through the same PE-006 transition action (D-06). Settlement therefore stays exactly-once however webhooks and polls interleave.
- It re-dispatches rows that were never submitted, or were left ambiguous, with their original ULID idempotency key. Pixelforge either replays the create that already landed or creates the generation now.

Serves grading #1 correct money (a charge or a release always happens, and exactly once) and #2 robustness (missing webhooks, dead workers).

## Acceptance Criteria

**Contract**
1. The Pixelforge contract gains a retrieve-generation operation.
    - The HTTP driver calls `GET /v1/generations/{id}` and returns the PE-006 snapshot DTO.
    - A 404 raises a typed `not_found` error, and every other error raises the existing PE-009 and PE-013 typed errors.
    - Each call takes one unit from the PE-013 shared budget.
    - A failed poll is not retried inline. The row waits for a later run.
2. The fake driver answers retrieve from its own state: the current status, usage, rates and error of its generation. An unknown id raises `not_found`.
3. The fake driver can drop webhooks so that the poller is the only signal. The generation still advances, but no webhook is sent:
    - A fake-only keyword, `[no-webhooks]`, drops every webhook for that one generation. Like PE-010's `[expire]`, it is case-insensitive and can appear anywhere in the prompt.
    - A config switch, off by default, drops webhooks for every generation, for manual local testing.

    E2E uses only the keyword. Playwright runs fully parallel against shared containers, so a global switch would change every other spec.

**Scheduling**
4. The reconciler runs every minute with no overlapping runs and on one server only. A test asserts the schedule entry. An exception on one row is logged with the generation id, and the run continues with the remaining rows.

**Polling stale rows**
5. A row is polled when it is non-terminal, has a `provider_id`, and its last provider update is older than a threshold: 2 min for queued, 3 min for processing.
    - This story adds the last-provider-update timestamp. The PE-006 transition action stamps it whenever it applies a snapshot, whatever the path. Ignored and conflicting snapshots still write nothing (PE-006 AC 9).
    - Staleness is never judged by `updated_at`, which also changes on PE-013's markers and on this story's own bookkeeping.
    - Terminal rows are never selected.

    Example: a processing row last updated by a webhook 2 min 50 s ago is skipped, and at 3 min 10 s it is polled.
6. A poll that returns an unchanged non-terminal status writes no ledger rows; the transition action reports it as ignored.
    - The reconciler itself records when the row was checked.
    - The recheck interval starts at `PIXELFORGE_RECONCILE_RECHECK` (default 1 min). It grows with the row's age, up to at most once every 15 min for rows older than 1 h.
    - A row stuck for 2 h is polled at most 4 times in an hour.
7. A poll that returns `succeeded` settles exactly once. Worked example: prompt `[succeed] a red fox`, 19 code points, low quality, 1024x1024, no references.
    - Text tokens: e = ceil(19 / 4) = 5, so the text bounds are 4 to ceil(5 × 1.15) = 6.
    - Max provider cost: 6 × 5,000 + 280 × 40,000 + 500 × 12,500 = 17,480,000 n$.
    - Hold: ceil(17,480,000 × 125 / 100) = 21,850,000 n$ ($0.021850000).
    - The poll returns usage of 5 input text, 0 input image, 280 output and 312 reasoning tokens, at rates 5.00 / 10.00 / 40.00 / 12.50.
    - Provider cost: 5 × 5,000 + 280 × 40,000 + 312 × 12,500 = 25,000 + 11,200,000 + 3,900,000 = 15,125,000 n$ ($0.015125000).
    - User charge: ceil(15,125,000 × 125 / 100) = 18,906,250 n$ ($0.018906250, shown as $0.0189).
    - The hold of 21,850,000 n$ is released.
8. A `generation.succeeded` webhook for that generation that arrives later, delivered twice under `[duplicate]`, gets `200` each time and writes no ledger rows.
9. A poll that returns `failed` releases the 21,850,000 n$ hold, charges nothing, and stores the `error`. History then shows the PE-012 copy for that `error.code` (`generation_failed` or `internal_error`).
10. A poll never moves a row backwards. A poll that returns `processing` for a locally queued row advances it to processing without writing ledger rows. A poll that returns `queued` for a locally processing row changes nothing.
11. The same `succeeded` snapshot can arrive through a webhook and through a poll, in either order and including when the two overlap. The result is exactly one release and one charge in the ledger, with provider cost and user charge stored once on the generation. The PE-007 row lock and DB unique constraints guarantee this. The path that loses the race finishes as a no-op, and the scheduled run still succeeds.

**Re-dispatching unsubmitted or ambiguous rows**
12. A row with no `provider_id` is re-dispatched when either:
    - PE-013 handed it over (PE-013 AC 23), meaning its submit job stopped while the ambiguity latch was set; or
    - its most recent submit attempt is older than `PIXELFORGE_REDISPATCH_AFTER` (default 5 min). If no attempt was ever recorded, its creation time counts instead. This covers a worker that died before or between attempts.

    A row with a more recent attempt is left alone, because its submit job may still be retrying. Example: last attempt 6 min ago → re-dispatched; 4 min ago → not re-dispatched.

    Repeat re-dispatches of the same row follow the AC 6 cadence. A row that stays ambiguous is therefore retried at most once a minute at first, and at most every 15 min once it is older than 1 h.
13. Re-dispatch reuses the **same** row: the same ULID `Idempotency-Key`, the same `metadata.generation_id` and a byte-identical body. It never creates a new row or a new key.
    - It always sends at least one create attempt, even when PE-013's submit window has already ended.
    - If that attempt is again not definitive, the row is handed back to the reconciler.
14. If the original create had landed, Pixelforge answers `202` with `Idempotent-Replayed: true`. That snapshot is applied once and the `provider_id` is stored, with no second hold and no second charge. If it had not landed, the generation is created now.
15. There is never more than one create request for a generation in flight at a time. A test dispatches a re-dispatch while an earlier submit job is still waiting to retry, and sees the two attempts run one after the other, never concurrently.

**Budget and ordering**
16. Each run handles at most `PIXELFORGE_RECONCILE_BATCH` rows (default 20), oldest first.
17. Polls stop for the run when fewer than `PIXELFORGE_SUBMIT_RESERVE` budget units (default 10) remain in the current minute, so new submits keep priority. Example: with 12 units left in the minute, only 2 polls are sent. Re-dispatched submit jobs take budget units through the PE-013 check when they run.

**Stuck and lost rows**
18. `not_found` is logged as a warning with the generation id and the provider id, and the row is left unchanged for that run.
19. A non-terminal row older than `PIXELFORGE_STUCK_AFTER` (default 15 min after creation) shows "Taking longer than usual — we're checking with Pixelforge" in History. One warning is logged per row.
20. Giving up. A row with no `provider_id` that is still non-terminal `PIXELFORGE_GIVE_UP_AFTER` (default 24 h) after creation is failed locally as `lost` through the PE-006 transition action. Such a row is always `pending`, which is the only state in which PE-006 AC 13 lets a local failure end a generation.
    - Its hold is released (21,850,000 n$ in the AC 7 example), nothing is charged, and an error-level log is written.
    - The copy reads "We couldn't confirm this generation with Pixelforge. You were not charged."
    - If a `lost` row later arrives as `succeeded`, for example through a webhook matched by `metadata.generation_id`, only provider cost is recorded (D-09). In the AC 7 example that is 15,125,000 n$. The user charge stays 0 and the status stays failed.
21. A row with a `provider_id` is never given up, because Pixelforge confirmed it and PE-006 AC 13 refuses a local failure on it.
    - It is polled at the slowed cadence until Pixelforge reports a terminal state.
    - If every poll has answered `not_found` for longer than `PIXELFORGE_GIVE_UP_AFTER`, one error-level log line names the generation id and the provider id for the operator.
    - The row keeps its hold and the stuck notice.

**E2E**
22. Playwright: a `[succeed] [no-webhooks]` prompt still reaches Succeeded in History without a reload. It shows its image and its charge next to the estimate. Specs running in parallel keep their webhooks. The short thresholds and cadence come from `.env.example` (Technical Notes, Config).

## Scope

### In Scope
- The retrieve-generation operation on the contract: the HTTP driver (typed `not_found`) and the fake driver (answered from fake state).
- The fake's `[no-webhooks]` keyword, plus a global drop switch that is off by default.
- The last-provider-update timestamp, stamped by the PE-006 transition action whenever it applies a snapshot.
- A scheduled reconcile command that runs every minute, never overlaps and runs on one server.
- Selecting stale queued or processing rows by their last provider update, with a recheck cadence that slows with age.
- Poll → PE-006 transition action → charge or release exactly once.
- Re-dispatching the PE-009 submit job with the same ULID key, for rows with no `provider_id` that PE-013 handed over or whose submit attempts stopped, at the AC 6 cadence.
- A bounded batch per run, oldest first, inside the shared budget, with a reserve kept for submits.
- The `not_found` warning and the stuck notice.
- The give-up rule (`lost`, free) for rows with no provider id, and the never-give-up rule for rows with one.
- `lost` appended to PE-009's failure-reason enum, and the new copy appended to the PE-012 mapping.
- PHP unit and feature tests, JS unit and integration tests for the new copy, and one Playwright spec.

### Out of Scope
- The local and E2E scheduler container: already done in bootstrap PR #6 (see Technical Notes).
- Sweeping `GET /v1/generations` listings to discover generations we don't know about.
- Usage reconciliation against `GET /v1/usage` (PE-018).
- Verifying the production scheduler cron and the reconciler health signal (PE-015).
- Alerting.
- Provider-limit and retry handling on the submit path (PE-013).

## Technical Notes
- **Depends on:**
  - PE-010-fake-pixelforge-driver: fake state and signed webhooks.
  - PE-013-provider-limits-retries-and-throttle: the shared budget, the submit bookkeeping and hand-off flag (PE-013 AC 23), and the time-bounded submit job.
  - It also builds on PE-006 (the transition action, which this story extends to stamp the last provider update) and PE-007 (the row lock, the unique ledger constraints, and provider cost stored on the generation).
- **Prerequisite, done in bootstrap PR #6:** `.docker/entrypoint.sh` has a `scheduler` mode (supervised `schedule:work`). `docker-compose.yml` has a `scheduler` service that `make up` starts, and the CI `e2e` job runs `scheduler` and `worker` containers next to `app`, so AC 22 can run. The production scheduler cron comes from PE-004.
- **Decisions:**
  - D-05;
  - D-06 (the poller is the third caller of the single transition action);
  - D-09;
  - D-14.

  See requirements.md §4, rows "Webhooks never arrive" and "Worker died before submit".
- **Decisions-log row**, to be added to requirements.md §5 when this story is planned. Use the next free D-number: PE-002, PE-006 and PE-008 add rows earlier, so this will not be D-15. The row: "Give up only on generations Pixelforge never confirmed (no provider id): after 24 h they are failed `lost` and free; a late provider success records provider cost only (D-09). Generations with a provider id are polled until terminal and never failed locally."
  - This is a narrow, deliberate exception to PE-013's rule "fail only when nothing was created". It applies only to pending rows, which PE-006 AC 13 already allows to fail locally.
  - It keeps History truthful and releases the hold. It never stops tracking a generation Pixelforge knows about, so PE-016 and PE-018 provider costs stay complete.
- **Pixelforge facts** (https://pixelforge.promptedit.dev/docs#retrieve-generation, #webhooks, #rate-limits, #idempotency):
  - `GET /v1/generations/{id}` returns the current object. An id from another account returns `404 not_found`.
  - Webhooks are retried after 10 s, 30 s, 2 m, 10 m and 15 m (up to 6 attempts in total), then given up. The docs do not say whether these are gaps or offsets. Polling after 3 min is deliberately earlier, and it is safe because the transition is idempotent.
  - Polling counts toward the 60 rpm account limit.
  - Queueing takes a few seconds and processing takes 5–30 s, or 60–90 s with `[slow]`.
  - Terminal states are final.
  - A replay returns the generation as it is now. It does not count against the concurrency or daily limits, but it does count toward rpm.
- **Config:** the thresholds, recheck cadence, batch size, reserve, stuck time and give-up time are config values, and their defaults are the production values above.
  - There is no separate E2E environment: `make e2e` runs against the dev containers, and the CI E2E image is built from `.env.example` (PE-010).
  - `.env.example` therefore sets the short values and a sub-minute schedule, so the spec finishes within its timeout.
  - Production sets none of them and runs every minute.
- **Copy to add to the PE-012 mapping:**
  - stuck: "Taking longer than usual — we're checking with Pixelforge";
  - `lost` (spelled as in PE-009's enum): "We couldn't confirm this generation with Pixelforge. You were not charged."
- **Required test layers:**
  - **PHP unit:**
    - stale selection by last provider update: thresholds by status, the 2 min 50 s and 3 min 10 s boundary, terminal rows excluded, oldest first, the batch cap;
    - the recheck cadence by age;
    - the re-dispatch rule: the hand-off flag, 6 min vs 4 min since the last attempt, and the cadence for repeats;
    - the budget and reserve arithmetic.
  - **PHP feature:**
    - retrieve through `Http::fake`: 200 → DTO, 404 → `not_found`;
    - the transition action stamps the last provider update only when it applies a snapshot;
    - `[no-webhooks]` drops webhooks for that generation only;
    - a poll settles once with the AC 7 numbers, and a later duplicate webhook is a no-op;
    - webhook and poll in both orders → one charge;
    - a poll returning failed releases the hold;
    - a poll never regresses a row;
    - re-dispatch sends the same key and body, still sends one attempt after the window, and a replay is applied once;
    - no concurrent create requests for the same generation;
    - the budget and reserve are respected;
    - `not_found` is logged;
    - give-up → `lost` and free, then a late success → provider cost only;
    - a row with a provider id is never given up, and after 24 h of `not_found` it is logged once;
    - the schedule is registered.
  - **JS unit, or PHP unit if PE-012 put the mapping on the server:** the new copy entries.
  - **JS integration:** the History row in its stuck and `lost` states.
  - **E2E** (Playwright, fake driver): `[succeed] [no-webhooks]` reaches Succeeded with its actual cost (AC 22).
- **PR size:** an estimated ~480 changed lines with the timestamp and the extra rules, over the budget. Split into two stories before planning, one PR each:
  - PE-014a: the retrieve operation, the fake's retrieve and `[no-webhooks]`, the last-provider-update timestamp, stale polling and its cadence, the schedule and the E2E spec.
  - PE-014b: re-dispatch, the stuck, `lost` and never-give-up rules, and their copy.

## Mockups / References
```text
History — catch-up states (same card format as PE-011/PE-012)

┌───────────────────────────────────────────────────────────────────┐
│ [Processing]  [succeed] a red fox           Low · 1024×1024       │
│               Est. $0.0140 – $0.0219                              │
└───────────────────────────────────────────────────────────────────┘
        │ webhook dropped; the reconciler polls after 3 min
        ▼
┌───────────────────────────────────────────────────────────────────┐
│ [Succeeded]   [succeed] a red fox           Low · 1024×1024       │
│ [ image ]     Charged $0.0189                                     │
│               Est. $0.0140 – $0.0219                              │
└───────────────────────────────────────────────────────────────────┘

Stuck (> 15 min, still non-terminal):
│ [Processing]  Taking longer than usual — we're checking with Pixelforge │

Given up (> 24 h, never confirmed by Pixelforge):
│ [Failed] Not charged                                              │
│   We couldn't confirm this generation with Pixelforge.            │
│   You were not charged.                                           │

Figures: min 14,025,000 n$, max 21,850,000 n$, actual charge
18,906,250 n$. The 4-decimal rendering comes from money.ts (PE-005);
0.021850000 is a half-way value and shows as $0.0219 with
half-away-from-zero rounding.
```
- https://pixelforge.promptedit.dev/docs#retrieve-generation
- https://pixelforge.promptedit.dev/docs#webhooks
- https://pixelforge.promptedit.dev/docs#rate-limits
- https://pixelforge.promptedit.dev/docs#idempotency
- requirements.md §2.3, §2.5 and §4
