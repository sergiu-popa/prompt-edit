# Review — PE-013-provider-limits-retries-and-throttle

## Round 1

**Verdict: APPROVE**

Scope reviewed: the 23 `[PE-013]` commits `b78cae6..5301b82` on `pe-013-provider-limits-retries-and-throttle` (`origin/main..HEAD`), against `story.md` and `plan.md`. That is the plan, 19 task commits, two test-isolation fix-ups (`88c95d1`, `13c289e`), the decisions and the bookkeeping. All 19 plan tasks are checked.

Checks run on HEAD (`5301b82`):
- `make check` exits 0. Pint and Larastan are clean. PHPUnit runs 1,431 tests (35,758 assertions), all passing. ESLint, Prettier and vue-tsc are clean. Vitest runs 496 tests in 31 files, all passing.
- `make e2e` (after `make restart-worker`) exits 0, with 40 passed and 2 skipped. The skips are the existing touch-only and mouse-only rows, one per project. The new AC 22 test passes on desktop (19.5 s) and mobile (19.3 s).

There is no Critical finding. The one Warning is about commit hygiene. It cannot be fixed without rewriting history, which the pipeline forbids, so it is acknowledged. Four optional Suggestions follow.

### Summary

The implementation follows the plan class for class. Its deviations from the story are recorded in the plan's Story clarifications and in D-44 to D-48: one PR instead of the PE-013a/b split, "Queued" meaning our `pending` status, and the note hidden by status rather than cleared.

- **Shared budget (AC 1, 2, D-44):**
  - `ThrottledPixelforgeClient` wraps whichever driver `AppServiceProvider::pixelforgeClient()` builds, so no caller can get past it.
  - It increments first and compares after, on a `RateLimiter` over the `database` store, which config pins regardless of `CACHE_STORE`. The database store's `increment` is a row-locked read-modify-write, so two workers cannot both take the last unit.
  - `SubmitGenerationRateBudgetTest` proves the count is shared: it forgets the store driver between runs and asserts the count is a row in `cache`.
  - `HttpPixelforgeClientTest` now targets the concrete driver, so it leaves no `cache` rows behind (a risk listed in the plan).
- **Bookkeeping (AC 12, 13, 23, D-45):**
  - `SubmitBookkeeping` is pure and immutable.
  - `Generation::recordSubmitBookkeeping()` writes only the dirty `submit_*` columns, through the casts, with `toBase()`, so `updated_at`, status, costs and the ledger are never touched.
  - Each start is recorded, with a null outcome, before the budget and the request. A run found with no outcome therefore latches at its own start, and `isAmbiguous()` covers both cases.
  - The transition action re-reads the row under its own lock (`TransitionGenerationAction.php:64`), so the in-memory instance's sync does not interfere.
- **Retry policy (AC 3–5, 7–9, 11, 14, 17, D-47):**
  - `SubmitRetryPolicy` is a direct reading of the plan's table. `Retry-After` is a floor, the 503 doubling is anchored on the first 503, and the deadline term applies only when the generation is not latched.
  - The window turns any retry that would start at or after its end into a stop. `decide()` throws `LogicException` for `accepted`, `rejected` and null, which `afterFailure` can never pass it.
  - The match arms are exhaustive over both enums, so a future case fails Larastan.
- **Free failures (AC 8, 11, 15, 17):**
  - Every free failure goes through `TransitionGenerationAction`: `failLocally` when a response is in hand, `StopSubmittingGenerationAction` at the window's end.
  - The AC 15 worked example (86,318,750 n$, 9 creates, failed at 10:02:00) and the AC 16 sequence (62,775,000 / 78,468,750, three entries, net −78,468,750) are asserted with the story's exact numbers.
  - A second stop writes nothing, because the action exits on a non-`pending` or already handed-over row.
- **Ambiguity is never failed (AC 12, 13):** all seven ambiguous kinds latch and are released after 5 s. A latched generation outlives the overload deadline, the concurrency max wait, the daily limit and the 401 limit, and is handed over `pending` with its hold. The `[x]` rows in `SubmitGenerationLimitsTest` and `SubmitGenerationRetriesTest` cover each of these.
- **Time-bounded job (AC 17, 19, D-46):**
  - `#[Tries]` is gone, and `retryUntil` is computed once at dispatch (window plus a 600 s grace). `failed()` logs and runs the same stop.
  - `SubmitGenerationWorkerTest` drives the real `queue:work --once --tries=3` on the `database` queue. Five releases do not fail the job, and past `retryUntil` the worker fails it and the generation fails free.
  - AC 19's order is read from config, and the payload's `timeout` equals `submit.job_timeout_seconds`.
- **401 (AC 14):** three tries 20 s apart, then `service_unavailable`. Exactly one critical entry is written per generation, because `reachedInvalidApiKeyLimit` uses `===` on the count, and the latched case proves no second entry. The test asserts the exact context and checks that the key, `Bearer` and every header name are absent.
- **History (AC 3, 5, 6, 21, 22):**
  - `status_note` is sent only while the row is `pending`. A 202, a replay or a webhook therefore hides it in the same transaction, and `test_the_free_slot_marker_clears_once_pixelforge_accepts_it` pins AC 6.
  - The card renders the note as text, between `[data-meta]` and the money row as in the mockup. It adds no `[data-tone]`, so the badge stays a neutral "Queued".
  - The four new failure messages show "Not charged" with the estimate.
  - The E2E test derives its timeouts from the same `PIXELFORGE_OVERLOADED_DEADLINE` the app reads, uses web-first assertions only, and proves there was no reload.
- **Config and environment (D-48):** every bound is config, and each default is production's. The compose anchor and `.env.example` carry the dev values, and `LocalEnvTemplateTest` pins that they match. `phpunit.xml` clears both names in `$_ENV` and `$_SERVER`, so the suite always runs with production's bounds.

Security: no secret reaches a log. Every context comes from `ReportsPixelforgeContext::context()`, which holds `error_code`, `status` and `retry_after_seconds`. The note is rendered with text interpolation, and the `<b>x</b>` row proves it. No new route or mass-assignable column is added, and the `submit_*` columns stay out of `#[Fillable]`.

The constant-field check: `status_note` is not constant under `ownedBy`. It varies with status and note.

Performance: the bookkeeping adds at most two single-row UPDATEs per attempt and no query to History. The resource reads columns of the row it already has.

### Commit hygiene

- Each task commit carries its code with its tests. The plan (`b78cae6`), the decisions (`8972b88`) and the bookkeeping (`5301b82`) have their own commits.
- Task 16 (`f8eba50`) adds `status_note` to the TS type together with the fixtures that need it, so vue-tsc stays green at that commit.
- All subjects start with `[PE-013]`, are imperative and are at most 68 characters. All 23 messages end with the trailer. Bodies appear where the reason isn't obvious (`0550679`, `791d411`, `88c95d1`, `13c289e`).
- The cut point after task 12 (`636f03a`) is clean. Until tasks 13–15 land, a concurrency 429, a daily limit and a 401 are retried like a rate limit, which `791d411`'s body explains.

### Warnings

- [x] **W1. Two commits were red until a later fix-up commit.** — acknowledged: fixing it needs a history rewrite, which the pipeline forbids. Each fix-up names what it fixes, HEAD is green, and the user can squash the pairs at merge if they want.
  - `ad2a4dd` (`[PE-013] Prove two job runs share one rate budget`) fails in the full suite. `13c289e`'s body says so: "ad2a4dd's budget test failed in the full suite until this". `SubmitGenerationAfterCommitTest` commits for real and left `cache` rows behind, and those rows spent the budget of the next test.
  - `0550679` (`[PE-013] Take every Pixelforge call from one shared rate budget`) says "The suite forces the budget variable empty, so a dev container's 600 never reaches a test". That held only for `$_ENV`. After `make up` recreated the containers with the anchor, `PixelforgeConfigTest`'s default-50 rows read 600 from `$_SERVER` until `88c95d1` cleared it there too.
  - Why it matters: the rule is that every commit passes its tests, so `git bisect` and a commit-by-commit review never land on a red tree.
  - Next time: run the full `make check` (not a filtered run) before each commit, and put an isolation fix before the commit that exposes it.

### Suggestions

- [ ] **S1. `SubmitDecision::isStop()` is only used by tests.** `app/Domain/Generation/DataTransferObjects/SubmitDecision.php:42` is called only from `SubmitRetryPolicyTest` (lines 123, 288, 298 and 349). `SubmitGenerationAction::afterFailure()` (`:148-166`) instead checks `retryInSeconds` and `failureReason` and treats what remains as a stop. Either branch on `isStop()` in production as well, or drop it and have the tests assert both fields are null.
- [ ] **S2. The budget is spent even when nothing can be sent.** `ThrottledPixelforgeClient::createGeneration()` (`app/Support/Pixelforge/ThrottledPixelforgeClient.php:38-43`) takes a unit before the inner `HttpPixelforgeClient` throws `PixelforgeNotConfiguredException` (`HttpPixelforgeClient.php:54-55`). With no key set, every pending generation spends one unit a minute and sends nothing. That is harmless, but it contradicts the class docblock ("every outbound Pixelforge call"). Optionally, return early through the inner client's `isConfigured()` before taking the unit, or reword the docblock and D-44 to "every call".
- [ ] **S3. The overload and concurrency anchors are never reset.** `SubmitRetryPolicy.php:162` and `:185` measure from the first 503 and the first concurrency 429 of the whole window.
  - Example: a 503 at 10:00, then rate limits until 10:03, then one more 503 fails the generation at once with `provider_overloaded`, after a single fresh 503.
  - This matches the story's wording ("after its first 503", "after its first concurrency 429"), so nothing needs to change now. Consider recording it in D-47, so PE-014 and later readers know the deadline is per window and not per streak.
- [ ] **S4. AC 19's order holds only for the default request timeout.** `pixelforge.request_timeout_seconds` reads `PIXELFORGE_REQUEST_TIMEOUT` (`config/pixelforge.php:38`, from PE-009), while `submit.job_timeout_seconds` is a fixed 60 (`:83`). An operator who sets the request timeout to 60 or more silently breaks the nesting, and `SubmitGenerationDispatchTest` only checks the defaults. Optionally, cap the request timeout's `max_range` below the job timeout in config, or derive the job timeout from it.
