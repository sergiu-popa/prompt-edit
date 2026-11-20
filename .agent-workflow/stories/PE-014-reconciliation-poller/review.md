# Review — PE-014-reconciliation-poller

## Round 1

**Verdict: REQUEST CHANGES**

Scope reviewed: the 23 `[PE-014]` commits `3ae57c6..c100dd4` on `pe-014-reconciliation-poller` (`origin/main..HEAD`), against `story.md` and `plan.md`. That is the plan, 21 task commits, the decisions and the bookkeeping. All 21 plan tasks are checked.

Checks run on HEAD (`c100dd4`):
- `make check` exits 0. Pint (275 files) and Larastan are clean. PHPUnit runs 1,651 tests (36,682 assertions), all passing. ESLint, Prettier and vue-tsc are clean. Vitest runs 498 tests in 31 files, all passing.
- `make e2e` exits 0, with 42 passed and 2 skipped. The skips are the existing touch-only and mouse-only rows of `generate.spec.ts`, one per project. The new AC 22 test passes on desktop (10.3 s) and mobile (13.6 s).

There is no Critical finding. Two Warnings must change, and three optional Suggestions follow.

### Summary

The implementation follows the plan class for class. Its deviations from the story are recorded in the plan's Story clarifications and in D-49 to D-56: one PR instead of the PE-014a/b split, the cadence measured from `created_at`, and the stuck note computed without a column.

- **Contract (AC 1, 2):**
  - `retrieveGeneration()` is on the contract. `HttpPixelforgeClient` shares one `request()` builder with create, so the token, Accept, timeouts and no-redirect rule are identical. The id is `rawurlencode`d into one path segment. `errorFrom()` and `generationFrom()` are reused, so a documented 404 is `NotFoundException` and anything unreadable is ambiguous.
  - The fake answers from its stored snapshot, and an unknown id raises `NotFoundException`.
  - `ThrottledPixelforgeClient` takes one unit before every retrieve.
- **Dropped webhooks (AC 3, D-55):** `[no-webhooks]` and the switch are both decided at create and stored as `drops_webhooks`. `FakeWebhookSender::deliver()` returns before it signs anything. The lifecycle test shows a second generation in the same test still gets its webhooks.
- **Staleness and cadence (AC 5, 6, D-50):**
  - Only `TransitionGenerationAction::snapshotAttributes()` stamps `last_provider_update_at`, so ignored and refused snapshots write nothing. The migration backfills confirmed rows from `updated_at`.
  - `reconcile_*` writes go through `writeColumnsOnly()`, shared with the submit bookkeeping. They never touch `updated_at`, status, costs or the ledger.
  - `recheckAt()` is written before the request, so a poll that throws still waits its turn. The 2 h row gets at most 4 GETs in an hour (`ReconcilePollTest`).
- **Settlement (AC 7–11, D-06):** polls go through the single transition action. `ReconcileSettlementTest` asserts the AC 7 numbers exactly (15,125,000 / 18,906,250, release of 21,850,000), the duplicate webhook as a no-op, both `failed` codes with History's copy, and one charge for webhook→poll, poll→webhook and a webhook delivered inside the poll's request.
- **Budget (AC 16, 17, D-51):** `PixelforgeRequestBudget` owns the key, store and limit, so the reserve check reads the same count `take()` compares. The poll step re-reads `remaining()` before each poll, and with 12 units left it sends exactly 2 polls. A spent budget or a 429 stops the run's polls. The unsubmitted step gets the rest of the batch.
- **Schedule (AC 4, D-54):** `withoutOverlapping(expiresAt: 15)` and `onOneServer()`. `scheduleEvery()` maps only Laravel's sub-minute frequencies, and config collapses anything else to 60. Every row is isolated with a `Throwable` catch that logs the ULID, the provider id and the exception class.
- **Re-dispatch (AC 12–15, D-52, D-53):**
  - `awaitingRedispatch()` covers handed over, last attempt older than 5 min, and never attempted with creation older than 5 min.
  - The owed run skips only the start-of-run window check, and only while no attempt has started since the re-dispatch instant. Duplicate re-dispatches therefore collapse into one attempt.
  - The create lock covers the run from the re-read to the recorded outcome, on the pinned database store, with a TTL past the job's timeout. `SubmitGenerationCreateLockTest` counts creates in flight inside the `Http::fake` callback and shows they never overlap. A dead run's lock holds a later run off until it expires.
- **Give-up and never-give-up (AC 18, 20, 21, D-49):** a pending row past 24 h is failed `lost` through the transition, free. A later success records provider cost only. A confirmed row answered `not_found` keeps its hold, and after more than 24 h of `not_found` it is logged once (`reconcile_not_found_reported_at`). Any snapshot clears the streak.
- **Stuck (AC 19, D-56):** the note is computed from `created_at` in `GenerationResource`, so it appears even while the reconciler is down, and it wins over PE-013's retry note. The reconciler logs one warning per row, marked by `reconcile_stuck_warned_at`.
- **Frontend (AC 22):** the card needs no markup change, and `HistoryItem.test.ts` covers the stuck and `lost` cards. The E2E timeout is derived from the same env values the app reads (`pixelforgeSeconds()`), and each term is commented.

Security: logs carry only ids, statuses, outcomes, instants and `context()`. No key, body or prompt is logged. The `reconcile_*` columns are not fillable. The copy is rendered as text. No new route is added.

The constant-field check: `status_note` is not constant under `ownedBy`.

### Commit hygiene

- Each task commit carries its code with its tests. Every new env name is cleared in `phpunit.xml` (`<env force>` and `<server>`) and listed in `.env.example` in the commit that first reads it. The plan (`3ae57c6`), the decisions (`2a62282`) and the bookkeeping (`c100dd4`) have their own commits.
- `f13a40a` changes an existing assertion in `SubmitGenerationRetriesTest`, and its body explains why (the stuck note now wins at 30 min).
- All subjects start with `[PE-014]`, are imperative and are at most 72 characters. All 23 messages end with the trailer.
- The cut point after task 11 (`670afc3`) is clean.
- D-49 to D-56 do not clash with existing numbers in `requirements.md` §5.

### Warnings

- [x] **W1. The stuck step scans the whole `generations` table on every run.**
  - `GenerationQueryBuilder::stuckUnwarned()` (`app/Domain/Generation/QueryBuilders/GenerationQueryBuilder.php:109-114`), read by `ReconcileGenerationsAction::warnAboutStuckGenerations()` (`app/Domain/Generation/Actions/ReconcileGenerationsAction.php:56-60`), filters on `status in (pending, queued, processing)`, `created_at <= …` and `reconcile_stuck_warned_at is null`, ordered by `created_at, id`. No index serves this query. The poll index is keyed on `last_provider_update_at` and its predicate requires `provider_id is not null`, and the pending index covers `pending` only. `EXPLAIN` on the dev database gives `Seq Scan on generations` with a `Sort`.
  - Why it matters: the reconciler runs every minute in production and every 5 s in dev and E2E. Terminal rows never leave this scan, because they are never marked as warned, so its cost grows with all history. The other two steps each got a partial index and an EXPLAIN test for this reason ("stays small however many terminal rows the table holds", D-50: "one indexed comparison").
  - Fix: add a partial index on `(created_at, id) where status in ('pending', 'queued', 'processing') and reconcile_stuck_warned_at is null`. Include the status list in the predicate, because terminal rows have a null marker too. Add it in a new migration, or in `2026_11_20_131140_add_stuck_warning_to_generations_table.php`, which is not merged yet. Add a `GenerationQueryBuilderTest` row with `SET LOCAL enable_seqscan = off` that names the index, like the two existing EXPLAIN rows (`:152`, `:228`). Name it in the `GenerationQueryBuilder` class docblock with the other two.
- [x] **W2. The give-up action logs "failed as lost and not charged" even when nothing was given up.**
  - `GiveUpUnconfirmedGenerationAction::execute()` (`app/Domain/Generation/Actions/GiveUpUnconfirmedGenerationAction.php:36-46`) writes its error log whatever the transition returned. The plan's give-up race is the case where this matters: a re-dispatched create lands between the selection and the transition. The row is then `queued`, and `classifyLocalFailure()` returns `RefusedConflict` (`TransitionGenerationAction.php:148-151`). Or a webhook already made it terminal, and the transition returns `IgnoredTerminal`. In both cases the operator gets two error lines: the transition's correct "Refused to fail a generation locally…", and then this action's "Gave up… it is failed as lost and not charged". The second is false, and in the refused case the generation will in fact be charged.
  - Why it matters: AC 20's error-level log is the operator's record of a free give-up. A log that wrongly claims a generation went free is misleading in exactly the money-sensitive race D-49 calls safe because it is "refused and logged". The path is also untested: `ReconcileGiveUpTest` only asserts `outcome: applied`.
  - Fix: write the "Gave up…" error only when the outcome is `Applied`. Otherwise write an info line, for example "A generation moved on before the reconciler gave up on it; nothing was given up.", with the ULID and the outcome, or nothing, since the transition already logs a refusal. Add a `ReconcileGiveUpTest` row that runs `GiveUpUnconfirmedGenerationAction` on a stale instance whose database row is already `queued` with a provider id. Assert that the row and ledger are unchanged and that no "Gave up" error is logged.

### Suggestions

These are optional, and none blocks QA.

- [x] **S1. Three docblocks were extended in place and now wrap unevenly.** `ReconcileGenerationsAction.php:20` and `ReconcilePolicy.php:16` each run to about 130 characters among lines of about 85. `HistoryItem.vue:21-22` breaks after "whatever its badge." and then runs on. Rewrap them, as PE-012's S2 did.
- [x] **S2. The give-up does not take the create lock.** At 24 h, a job re-dispatched up to one recheck earlier (≤ 15 min) can still be mid-create when the give-up runs. If that create lands, the row is `lost` with only the provider cost recorded (D-09): money-safe for the user, but the platform eats the cost. The plan accepts this ("only logged, not prevented"). `GiveUpUnconfirmedGenerationAction` could instead try `pixelforge-create:{ulid}` without blocking and skip the row this run if the lock is held, so it is retried at its next check. That closes the window cheaply. If you take this, reuse `SubmitGenerationAction::CREATE_LOCK_PREFIX` and the pinned store.
- [x] **S3. `GenerationResource` resolves `ReconcilePolicy` once per item.** `GenerationResource.php:84` calls `app(ReconcilePolicy::class)` for every card, and each call builds the object from nine `#[Config]` attributes. A History page of 20 items builds it 20 times. This is cheap, but `$this->app->singleton(ReconcilePolicy::class)` is not an option, because tests change config at runtime. A `once()` wrapper, or one resolution at collection level, would avoid the repeats. No change is needed now.

### Round 1 resolution (engineer)

- W1: `a9649f3` adds `generations_stuck_unwarned_index` on `(created_at, id) where status in ('pending', 'queued', 'processing') and reconcile_stuck_warned_at is null`, in a new migration, with an EXPLAIN row in `GenerationQueryBuilderTest` and a mention in the class docblock.
- W2: `d132823` writes the "Gave up…" error only for `Applied`; any other outcome logs an info line, "A generation moved on before the reconciler gave up on it; nothing was given up.". `ReconcileGiveUpTest` covers a row confirmed after its selection.
- S1: `513913d` rewraps the three docblocks.
- S2: `894fdfc` moves the lock's key, store and TTL into `PixelforgeCreateLock` with no behaviour change. `e9b8efd` makes the give-up take it without waiting and leave the row for the next run while a submit run holds it, with a `ReconcileGiveUpTest` row. `5b6c101` records this in D-53.
- S3: not changed. The reviewer said no change is needed now. `once()` would need care with tests that change config at runtime.

Checks: `make check` exits 0 (Pint 277 files, Larastan clean, 1,654 PHPUnit tests, ESLint, Prettier and vue-tsc clean, 498 Vitest tests). `make e2e` exits 0 (42 passed, 2 skipped).
