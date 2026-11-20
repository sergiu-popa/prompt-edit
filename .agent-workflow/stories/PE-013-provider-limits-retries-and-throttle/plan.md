# Plan — PE-013-provider-limits-retries-and-throttle

## Approach
Every Pixelforge call goes through a `ThrottledPixelforgeClient` decorator, which first takes one unit of a 50-a-minute, account-wide budget from Laravel's `RateLimiter` on the database cache store. The time-bounded submit job records each attempt in `submit_*` columns outside the transition action, and a pure `SubmitRetryPolicy` turns the outcome into a release after a wait, a free failure through the transition action, or a stop at the window's end, where `StopSubmittingGenerationAction` fails it free by its last outcome or, once any attempt was ambiguous, hands it to PE-014. History shows the persisted retry note on a waiting card and the new copy from `GenerationFailureCode`.

## Tasks
Each task is one TDD commit: write the failing test first, then the code, and get `make check` green before committing.
- After any task that changes worker code (3, 8–10, 13–15), run `make restart-worker`.
- After tasks 3 and 18 change `docker-compose.yml`, run `make up` (it recreates the changed services).
- After task 1, run `make migrate` on the dev database.
- Run `make e2e` for tasks 3, 9, 10, 17 and 18.
- Task 17 uses the `tailwindcss-development` skill.

Tasks 1–12 are the story's PE-013a: the budget, rate limits and overloads, ambiguity and the latch, the bookkeeping, the window and the timeout order. Tasks 13–19 are PE-013b: the concurrency wait, the daily limit, the 401, the History note, the copy, E2E and the docs. The cut point is after task 12 (see Size).

- [x] 1. Add migration `add_submit_bookkeeping_to_generations_table` with the nine columns in Data model, all nullable or defaulted. Add the string-backed enums `SubmitAttemptOutcome` and `SubmitRetryNote` (values only), and give `Generation` the casts and `@property` lines (not `#[Fillable]`). Test-first: `GenerationTest` rows: an existing-shape row reads null bookkeeping and a 0 count, and every cast round-trips. Commit: `[PE-013] Add submit bookkeeping columns to generations`
- [x] 2. Add the `SubmitBookkeeping` value object and `SubmitAttemptOutcome::retryNote()`. Test-first: `SubmitBookkeepingTest` (rows in Tests). Commit: `[PE-013] Model a generation's submit attempts as a value object`
- [x] 3. Add `RateBudgetExhaustedException` and `ThrottledPixelforgeClient`, add `rate_limit_per_minute` and `rate_limit_store` to `config/pixelforge.php`, and have `AppServiceProvider` wrap whichever driver it builds. Add `@throws RateBudgetExhaustedException` to the contract's docblock. Point `HttpPixelforgeClientTest::client()` at the concrete `HttpPixelforgeClient`, because that class has no RefreshDatabase and must not leave `cache` rows behind. Change the three binding assertions (`HttpPixelforgeClientTest`, `FakePixelforgeDriverTest`, `BootsWithoutPixelforgeTest`) to expect the decorator wrapping that driver. Add the dev budget: an `x-pixelforge-dev-bounds` anchor in `docker-compose.yml` with `PIXELFORGE_RATE_LIMIT_PER_MINUTE: ${PIXELFORGE_RATE_LIMIT_PER_MINUTE:-600}`, merged into app, worker and scheduler, plus `PIXELFORGE_RATE_LIMIT_PER_MINUTE=600` in `.env.example`. Test-first: `ThrottledPixelforgeClientTest` (unit), `PixelforgeConfigTest` rows, and `LocalEnvTemplateTest` rows (600, and the compose default equals it). Commit: `[PE-013] Take every Pixelforge call from one shared rate budget`
- [x] 4. Extract `PixelforgeApiException::retryAfterSecondsFrom(?string)` from `tryFromResponse`. Add `RateLimitExceededException::undocumented(?int)`, and have `HttpPixelforgeClient` throw it for any 429 that `tryFromResponse` does not type (AC 4). Move the "undocumented code at 429" row of `HttpPixelforgeClientTest::nonEnvelopeResponses` into a new 429 data set. Test-first: client rows (an unknown code at 429; an HTML 429 with `Retry-After: 9`; `overloaded` sent with a 429), all `RateLimitExceededException` carrying their `Retry-After`; a 503 with a non-`overloaded` code stays ambiguous. `PixelforgeApiExceptionTest` gets parse rows: `12` → 12, `0` → 0; `12.5`, ` 12`, `-1`, an HTTP-date, empty, 10 digits → null. Commit: `[PE-013] Read any undocumented 429 as a rate limit`
- [x] 5. Add the cases `provider_overloaded`, `provider_busy` and `service_unavailable` to both `LocalFailureReason` and `GenerationFailureCode`, with the copy in Story clarifications. Test-first: `GenerationFailureCodeTest` rows, and `GenerationHistoryStatusTest` rows for a locally failed row of each (message, null charge). Commit: `[PE-013] Add the overloaded, busy and unavailable failure copy`
- [x] 6. Add `SubmitDecision` and `SubmitRetryPolicy`, plus `submit.window_seconds` and `submit.overloaded_deadline_seconds` in config. Until tasks 13–15, `concurrency_limited`, `daily_limited` and `invalid_api_key` are decided like `rate_limited`. Test-first: `SubmitRetryPolicyTest` (the Retry policy table, the latch, the window) and `PixelforgeConfigTest` rows. Commit: `[PE-013] Decide each submit retry from the attempt history`
- [x] 7. Add `StopSubmittingGenerationAction` and `SubmitAttemptOutcome::windowEndReason()`, plus `GenerationFactory` states `submitAttempted(?SubmitAttemptOutcome $lastOutcome)` and `submitLatched()`. Test-first: `StopSubmittingGenerationActionTest` (rows in Tests). Commit: `[PE-013] Fail free or hand over when the submit window ends`
- [x] 8. Make `SubmitGenerationAction` record each attempt through `SubmitBookkeeping`: the start before the request, the outcome after it. It keeps today's rethrow for now. Add `SubmitAttemptOutcome::fromFailure()`. In `SubmitGenerationRejectionsTest` and `SubmitGenerationJobTest`, the "row unchanged" assertions now compare status, costs, provider id and ledger, because bookkeeping now changes. Test-first: rows asserting the bookkeeping after a 202 (`accepted`), a 400 (`rejected`), a 503, and a timeout (latched), and that a run finding an attempt with no outcome latches before it sends. Commit: `[PE-013] Record each submit attempt on the generation before sending`
- [x] 9. Make `SubmitGenerationAction::execute()` return the seconds until the next attempt, or null. Before starting, it stops through `StopSubmittingGenerationAction` once the window has ended. After a failure it applies the policy's decision: retry, fail free through `failLocally` (extended for this story's reasons) or stop. The job's `handle()` calls `release()` with that delay. `runSubmitJob()` returns the job with `withFakeQueueInteractions()`. `SubmitGenerationRejectionsTest::retriedFailures` changes from "rethrown" to "released after N s" (rows in Tests). `SubmitGenerationJobTest`'s lost-response and byte-identical tests, and `SubmitGenerationLoggingTest`'s retry lines, follow the change. Test-first: those rows, plus the AC 11 and AC 15 overload deadline row and a "next retry past the window → stop" row. Commit: `[PE-013] Release the submit job after each wait the policy decides`
- [x] 10. Give `SubmitGenerationJob` a `retryUntil()` (now + the window + `RETRY_UNTIL_GRACE_SECONDS` = 600) and a `$timeout` set from `submit.job_timeout_seconds` in its constructor, and drop `#[Tries]`. Keep `#[Backoff]`, which now applies only to unexpected exceptions. `failed()` logs, then runs `StopSubmittingGenerationAction`. Update `SubmitGenerationDispatchTest` (payload: `maxTries` null, `retryUntil`, `timeout` = config) and the `failed()` tests in `SubmitGenerationRejectionsTest` and `SubmitGenerationLoggingTest`. Test-first: the AC 19 order test reads config, plus `SubmitGenerationWorkerTest` (AC 17). Commit: `[PE-013] Bound the submit job by time, not by the worker's tries`
- [x] 11. Add `SubmitGenerationRateBudgetTest` (AC 1, AC 2). No production change is expected. Commit: `[PE-013] Prove two job runs share one rate budget`
- [x] 12. Add `SubmitGenerationRetriesTest` (AC 10, 12, 13, 16, 18, 20, 23). No production change is expected. Commit: `[PE-013] Prove retries create once, charge once and hand over`
- [x] 13. Add the `concurrency_limited` policy rows and `submit.concurrency_max_wait_seconds` (`PIXELFORGE_CONCURRENCY_MAX_WAIT`). Test-first: policy rows, config rows, and the concurrency rows of `SubmitGenerationLimitsTest` (AC 5, 7). Commit: `[PE-013] Wait for a free slot, then fail free after the max wait`
- [x] 14. Add `daily_limit` to both enums with its copy, and the `daily_limited` policy rows. Test-first: `GenerationFailureCodeTest` and status rows, policy rows, and the daily rows of `SubmitGenerationLimitsTest` (AC 8). Commit: `[PE-013] Fail free at Pixelforge's daily limit`
- [x] 15. Add the `invalid_api_key` policy rows, `submit.invalid_api_key_attempts` (3, no env), and the critical log, written once per generation when the count reaches the limit. Test-first: policy rows, and the 401 rows of `SubmitGenerationLimitsTest` (AC 14), including the no-secrets check. Commit: `[PE-013] Retry a refused API key briefly, then fail free and alert`
- [x] 16. Add `SubmitRetryNote::message()` and the resource's `status_note`. Add `status_note: string | null` to `types/generation.ts` and the TS fixtures in `Index.test.ts` and `HistoryItem.test.ts`. Test-first: `GenerationHistoryStatusTest` note rows, and `ITEM_KEYS` in `GenerationHistoryPageTest`. Commit: `[PE-013] Send a waiting generation's retry note to History`
- [x] 17. Render `[data-status-note]` in `HistoryItem` (see Frontend). Test-first: `HistoryItem.test.ts` rows (AC 3, 5, 21). Commit: `[PE-013] Show the retry note and the new failure copy on History`
- [x] 18. Add `PIXELFORGE_OVERLOADED_DEADLINE: ${PIXELFORGE_OVERLOADED_DEADLINE:-15}` to the compose anchor, merge the anchor into `playwright` too, and set `PIXELFORGE_OVERLOADED_DEADLINE=15` in `.env.example`. Add the overload timings to `tests/e2e/support/liveStatus.ts`, and the AC 22 test to `live-status.spec.ts`. Test-first: `LocalEnvTemplateTest` rows (15, and the compose default equals it). Commit: `[PE-013] Cover an overloaded prompt end to end`
- [x] 19. Add D-44 to D-48 to `requirements.md` §5, mark D-33's interim tries and backoff as superseded by D-46, and update the §4 rows for 429, 503, timeouts, 5xx and 401. In the README, update "How it copes with Pixelforge" (limits, overloads, ambiguity and the hand-off) and the Production worker line (`retryUntil` overrides `--tries=3`; the unit is unchanged). Commit: `[PE-013] Record the provider limits decisions`

## Story clarifications
- **PR size.** The story asks for a split into PE-013a and PE-013b. The project rule wins: one PR, with that split as the cut point after task 12.
  - Until tasks 13–15 land, a concurrency 429, a daily limit and a 401 are retried like a rate limit until the window ends.
  - That interim is safe: nothing fails locally that might exist at Pixelforge.
- **"Queued".** The story's "stays Queued" means our `pending` status, whose label is already "Queued", until Pixelforge accepts the generation. Every local failure in this story applies only to a `pending` row, which is all the transition action allows (`classifyLocalFailure`).
- **What an attempt is.** An attempt is one run of the job.
  - It is recorded before the budget is taken and before the request is sent. So budget waits count inside the window, and "no request was ever sent" (AC 17) covers `not_configured` and a job that never ran.
  - A run whose start is recorded but whose outcome is not died after it may have sent its request (AC 12), so it is ambiguous.
  - An unexpected exception (one thrown by the transition, say) also leaves no outcome. The next run therefore treats it as ambiguous, which is the safe side.
- **Clearing the note (AC 6).**
  - `submit_retry_note` is written with each retried outcome and at hand-over. The resource reads it only while the row is `pending`.
  - A 202, a replay or a webhook moves the row off `pending` inside the transition action's transaction, so the note goes in that same commit. The transition action needs no bookkeeping code.
  - A local failure makes the row `failed`, so its note disappears too.
- **Copy** (exactly as the story's table):
  - Notes (`SubmitRetryNote::message()`):
    - `retrying` reads "Pixelforge is busy — retrying automatically".
    - `waiting_for_slot` reads "Waiting for a free slot".
  - Failures (`GenerationFailureCode`):
    - `daily_limit` reads "Daily capacity reached, try after 00:00 UTC. You were not charged."
    - `provider_overloaded` reads "Pixelforge was overloaded and didn't accept this request. You were not charged."
    - `provider_busy` reads "Pixelforge stayed busy for too long, so we stopped waiting. You were not charged."
    - `service_unavailable` reads "Service unavailable — this generation wasn't sent. You were not charged."
- **Error fields of a local failure.**
  - A failure at a response (the deadline, the daily limit or a 401) stores Pixelforge's envelope, as `failLocally` does today.
  - A failure at the window's end has no response in hand, so it stores only the reason.
- **Unchanged.**
  - The definitive 400, 422 and 409 still fail free even after the latch (PE-009's scope). Their replay order is Pixelforge's.
  - A `404` at create is undocumented, so it stays ambiguous (D-31).
  - There is no proactive concurrency gating.
- **The 401 log.** It is `Log::critical`, written once per generation, when `submit_invalid_api_key_count` reaches the limit, whether or not the latch is set.
  - Its context holds `generation_id`, `error_code` and `status`.
  - The test asserts that the API key, `Bearer`, and the header names `Authorization`, `Idempotency-Key`, `Content-Type` and `Accept` appear nowhere in its message or context. The generation id is the Idempotency-Key's value, and it is expected.
- **Budget store.** `pixelforge.rate_limit_store` is pinned to `database` in config, with no env, and it ignores `CACHE_STORE`. Every test that submits therefore exercises the real store inside RefreshDatabase's transaction. Unit tests build the decorator over an `ArrayStore`.
- **Why the job-level `retryUntil` has a grace.** `retryUntil` is computed at dispatch, but the window is measured from the first attempt. The 10 min grace lets the action stop the job in the normal case. If queue latency eats the grace, the worker fails the job, and `failed()` stops it the same way.

## Retry policy
`SubmitRetryPolicy::decide(SubmitBookkeeping, ?int $retryAfterSeconds, CarbonImmutable $now): SubmitDecision` reads the last outcome from the bookkeeping. "Latched" means `SubmitBookkeeping::isAmbiguous()`. Every wait is at least 1 s.

| Last outcome | Wait | Fails free when (only if not latched) |
| --- | --- | --- |
| `budget_exhausted` | the budget's `availableIn` | never; only the window ends it |
| `rate_limited` | `Retry-After`, else 60 s | never; only the window ends it |
| `concurrency_limited` (task 13) | `Retry-After`, else 5 s | `provider_busy`, once now ≥ first concurrency 429 + max wait |
| `daily_limited` (task 14) | latched: 60 s | `daily_limit`, at once |
| `overloaded` | max(`Retry-After` else 2 s, min(30 s, seconds since the first 503, seconds left to the deadline)); the deadline term applies only when not latched | `provider_overloaded`, once now ≥ first 503 + deadline |
| `ambiguous` | clamp(seconds since the latch, 5 s, 60 s) | never, because it sets the latch |
| `invalid_api_key` (task 15) | 20 s; latched, from the limit on: 60 s | `service_unavailable`, at the limit (3rd 401) |
| `not_configured` | 60 s | never; only the window ends it |

- A retry whose next attempt would start at or after first attempt + window is a stop instead. `hasWindowEnded(SubmitBookkeeping, now)` answers the same question at the start of a run.
- A stop is `StopSubmittingGenerationAction`:
  - If the generation is latched, or an attempt has no outcome, it stays `pending` with its hold. The latch and `submit_handed_over_at` are set, and the note is set to `retrying`.
  - Otherwise it fails free with `windowEndReason()`:
    - `provider_busy` for `budget_exhausted`, `rate_limited`, `concurrency_limited` and `daily_limited`;
    - `provider_overloaded` for `overloaded`;
    - `service_unavailable` for `invalid_api_key` and `not_configured`, or when no attempt ever ran.
  - It does nothing to a row that is no longer `pending` or is already handed over.

## Data model
Migration `add_submit_bookkeeping_to_generations_table` adds the columns below. They are additive and nullable or defaulted, so the migration is instant on Postgres and safe with production rows. There is no index; PE-014 adds what its queries need. Only `SubmitGenerationAction` and `StopSubmittingGenerationAction` write them, each in one UPDATE of these columns alone (`toBase()`), never in the transition action and never touching status, costs or the ledger (AC 23).

| Column | Type | Written | Read by |
| --- | --- | --- | --- |
| `submit_first_attempted_at` | timestampTz null | once, at the first run's start | the window (AC 17), PE-014 |
| `submit_last_attempted_at` | timestampTz null | at each run's start, before the budget and the request | no-outcome detection (AC 12), PE-014 |
| `submit_last_outcome` | string null, cast `SubmitAttemptOutcome` | set to null at each start, then the outcome | the policy, the window-end reason |
| `submit_ambiguous_at` | timestampTz null | the first ambiguous outcome, or the start of an attempt found with no outcome; never cleared | the latch (AC 13), the ambiguous backoff, PE-014 |
| `submit_handed_over_at` | timestampTz null | only by the stop, when latched | PE-014 (AC 23) |
| `submit_first_overloaded_at` | timestampTz null | the first `503 overloaded` | the overload deadline and backoff (AC 11) |
| `submit_first_concurrency_limited_at` | timestampTz null | the first concurrency 429 | the max wait (AC 7) |
| `submit_invalid_api_key_count` | smallInteger default 0 | +1 per 401 | the 401 limit and its log (AC 14) |
| `submit_retry_note` | string null, cast `SubmitRetryNote` | with each retried outcome, and at hand-over | History, while `pending` (AC 3, 5, 6, 13) |

## Config
In `config/pixelforge.php`, each value with an env name uses the existing `filter_var` rule: a positive integer, else the default. The default is always the production value, and production sets none of these.

| Key | Env | Default | Dev / E2E | Task |
| --- | --- | --- | --- | --- |
| `rate_limit_per_minute` | `PIXELFORGE_RATE_LIMIT_PER_MINUTE` | 50 | 600 | 3 |
| `rate_limit_store` | — | `database` | same | 3 |
| `submit.window_seconds` | `PIXELFORGE_SUBMIT_WINDOW` | 1,800 | default | 6 |
| `submit.overloaded_deadline_seconds` | `PIXELFORGE_OVERLOADED_DEADLINE` | 120 | 15 | 6, 18 |
| `submit.job_timeout_seconds` | — | 60 | same | 10 |
| `submit.concurrency_max_wait_seconds` | `PIXELFORGE_CONCURRENCY_MAX_WAIT` | 600 | default | 13 |
| `submit.invalid_api_key_attempts` | — | 3 | same | 15 |

- `.env.example` lists the four env names with a seconds comment, with the dev values set and the rest left empty.
- The waits in the Retry policy table restate Pixelforge's documented `Retry-After` values and the story's examples, so they are constants on `SubmitRetryPolicy`, not config.
- **No droplet change.**
  - `retryUntil` overrides the systemd unit's `--tries=3`.
  - The job's own 60 s timeout wins over the unit's default `--timeout`.
  - `retry_after` stays at 90 s.

## Backend
All classes are `final` (enums aside) and declare `strict_types`.

| Class / file | Location | Role |
| --- | --- | --- |
| `SubmitAttemptOutcome` | `app/Domain/Generation/Enums/` | Cases: `accepted`, `rejected`, `budget_exhausted`, `rate_limited`, `concurrency_limited`, `daily_limited`, `overloaded`, `ambiguous`, `invalid_api_key`, `not_configured`. Methods:<br>- `retryNote()` (task 2): `waiting_for_slot` for concurrency, `retrying` for every other retried outcome, null for `accepted` and `rejected`;<br>- `windowEndReason()` (task 7), per the Retry policy;<br>- `fromFailure(ReportsPixelforgeContext)` (task 8): the budget exception, the three 429s, `overloaded`, `invalid_api_key` and `not_configured` map to their own cases; any other `PixelforgeApiException` (including 404) and `AmbiguousPixelforgeOutcomeException` map to `ambiguous`. |
| `SubmitRetryNote` | `app/Domain/Generation/Enums/` | `retrying`, `waiting_for_slot`, and `message()` (task 16). Consumers: `SubmitBookkeeping`, `GenerationResource`. |
| `LocalFailureReason`, `GenerationFailureCode` | `app/Domain/Generation/Enums/` | Gain `provider_overloaded`, `provider_busy` and `service_unavailable` (task 5), then `daily_limit` (task 14). The new copy is in Story clarifications. |
| `SubmitBookkeeping` | `app/Domain/Generation/ValueObjects/` | A readonly value object over the nine columns, with no I/O. Methods:<br>- `fromGeneration()`;<br>- `startAttempt($now)`: latch if the last attempt has no outcome, set the first attempt once, set the last attempt, null the outcome;<br>- `finishAttempt($outcome, $now)`: set the outcome, set each anchor once, latch on `ambiguous`, +1 on a 401, and the note from `retryNote()` when it has one;<br>- `handOver($now)`;<br>- `isAmbiguous()`: the latch is set, or an attempt has no outcome;<br>- `toAttributes()`.<br>Consumers: `SubmitGenerationAction`, `StopSubmittingGenerationAction`, `SubmitRetryPolicy`. |
| `SubmitRetryPolicy` | `app/Domain/Generation/ValueObjects/` | `#[Config]`-injected bounds. `decide()` and `hasWindowEnded()` follow the Retry policy table; the waits are named constants. Consumer: `SubmitGenerationAction`. |
| `SubmitDecision` | `app/Domain/Generation/DataTransferObjects/` | A readonly DTO with named constructors `retryIn(int)`, `failFree(LocalFailureReason)` and `stop()`. Consumer: `SubmitGenerationAction`. |
| `StopSubmittingGenerationAction` | `app/Domain/Generation/Actions/` | `execute(string $generationId): void`: the stop in the Retry policy. Its free failure goes through `TransitionGenerationAction`; a hand-off is logged at warning with the ULID and both attempt times. It does not depend on `PixelforgeClient`, so `failed()` works even when the client cannot be built. Consumers: `SubmitGenerationAction`, `SubmitGenerationJob::failed()`. |
| `SubmitGenerationAction` | `app/Domain/Generation/Actions/` | `execute(): ?int`:<br>- exit if terminal or a provider id is stored (AC 20);<br>- stop if the window has ended;<br>- record the attempt start, then send;<br>- 202 or replay: transition, then `accepted`;<br>- definitive rejection: `failLocally`, then `rejected`;<br>- any other failure: record the outcome, log at warning (`error_code`, `retry_in_seconds`), and apply the decision.<br>Consumers: the job. |
| `SubmitGenerationJob` | `app/Jobs/` | `handle()` releases after the returned delay. Task 10 adds `retryUntil()` and the config `$timeout`, removes `#[Tries]` and makes `failed()` stop. It still carries only the ULID, plus the timeout. |
| `Generation` | `app/Domain/Generation/Models/` | Casts (`immutable_datetime`, the two enums, integer) and `@property` lines. The columns stay out of `#[Fillable]`. |
| `GenerationFactory` | `database/factories/` | Task 7: `submitAttempted(?SubmitAttemptOutcome)` (first and last attempt one minute ago, that outcome, its anchor and note) and `submitLatched()`. |
| `ThrottledPixelforgeClient` | `app/Support/Pixelforge/` | Implements `PixelforgeClient`, around `public readonly PixelforgeClient $client`.<br>- `createGeneration()` increments the `RateLimiter` key `pixelforge-requests` (60 s decay) and only then compares the hits with the limit. Over the limit, it throws `RateBudgetExhaustedException` with `availableIn()` and sends nothing.<br>- `isConfigured()` delegates and takes nothing.<br>Built by `AppServiceProvider::pixelforgeClient()` over `new RateLimiter($cache->store(rate_limit_store))`. |
| `RateBudgetExhaustedException` | `app/Support/Pixelforge/Exceptions/` | Implements `ReportsPixelforgeContext`, with `public readonly int $retryAfterSeconds` and context `{error_code: rate_budget_exhausted, retry_after_seconds}`. |
| `PixelforgeApiException`, `RateLimitExceededException`, `HttpPixelforgeClient` | `app/Support/Pixelforge/…` | Task 4: `retryAfterSecondsFrom()`, `undocumented()`, and the 429 fallback. |
| `GenerationResource` | `app/Http/Resources/` | Task 16: `status_note` follows `failure_message`. It is the note's message while `pending`, else null, and the array-shape docblock is updated. |

## Frontend
| File | Role |
| --- | --- |
| `types/generation.ts` | `Generation.status_note: string \| null`: why a queued generation is waiting (`SubmitRetryNote`), null unless it is waiting to be retried. |
| `components/history/HistoryItem.vue` | `<p data-status-note>` sits after `[data-meta]` and before the money row, as in the mockup. It renders only when `status_note` is set, as text, with `text-sm wrap-break-word text-amber-800` (the warning Badge's text tone). It adds no new `[data-tone]` element, so `statusOf()` still finds the badge. The badge stays neutral "Queued". |
| `tests/e2e/support/liveStatus.ts` | `OVERLOADED_DEADLINE_MS` comes from `process.env.PIXELFORGE_OVERLOADED_DEADLINE`, else from `.env.example`'s line (CI's Playwright runs in the repo checkout, beside an app built from it). Also: `OVERLOADED_RETRY_AFTER_MS` = 2,000 (the fake's), `RETRY_NOTE_TIMEOUT_MS` = MARGIN × (worker sleep + poll), and `OVERLOADED_FAILURE_TIMEOUT_MS` = MARGIN × (deadline + Retry-After + 2 × worker sleep + poll). Each is commented with its source. |

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-44 | Every call through the Pixelforge contract first takes one unit of one account-wide budget: a `ThrottledPixelforgeClient` decorator around whichever driver is bound, over Laravel's `RateLimiter` on the database cache store (pinned in config, not `CACHE_STORE`), 50 a minute by default (`PIXELFORGE_RATE_LIMIT_PER_MINUTE`). It increments first and compares after. A spent budget throws `RateBudgetExhaustedException` and sends nothing. The fake is wrapped too, with 600 a minute in dev and E2E | The contract is the one door to Pixelforge, so PE-014's polls and PE-018's usage calls cannot skip the budget. The database store's increment is a row-locked read-modify-write, so every process shares one count (D-20), and two workers can never both take the last unit; `RateLimiter::attempt` checks before it hits. The window is fixed, so a rolling minute can see up to twice the limit at a boundary; Pixelforge's 429 handling is the backstop. Wrapping the fake exercises the decorator locally, and the high dev budget never slows parallel E2E. |
| D-45 | Submit bookkeeping lives on the generation in nine `submit_*` columns, written by the submit path in one UPDATE of those columns only. It is outside the transition action and never touches status, costs or the ledger. Each run records its start, and nulls its outcome, before the budget or the request, then records its outcome. A run found with no outcome is ambiguous. The latch is a timestamp that is never cleared. The retry note is its own column, shown only while the generation is `pending` | One job per generation runs at a time (D-33's nesting), so it is the only writer and needs no lock, and PE-014 reads plain columns. A separate note column keeps the note on screen while a retry is in flight. Because only a `pending` row shows it, the transition that advances the row (a 202, a replay, a webhook) clears it in the same transaction without knowing about it. |
| D-46 | The submit job is bounded by time. `retryUntil` (dispatch + `PIXELFORGE_SUBMIT_WINDOW` + a 10 min grace) overrides the worker's `--tries=3`. The action measures the window from the first attempt, releases the job itself after each wait, and stops it when the window has ended or the next attempt would start after it; `failed()` runs the same stop. The job's 60 s timeout is config, between the HTTP timeout and `retry_after`. This supersedes D-33's interim tries and backoff | Laravel gives `retryUntil` precedence over `tries`, and every `release()` counts as an attempt, so `--tries=3` would otherwise end a waiting job after three waits. Measuring from the first attempt means queue latency never shortens the window. The grace only decides which path stops the job, and both stop it the same way. Production's systemd unit needs no change. |
| D-47 | Retries treat `Retry-After` as a floor. A 503 backs off by doubling from the first 503, capped at 30 s, and never past the overload deadline. An ambiguous outcome waits clamp(time since the latch, 5 s, 60 s). A 401 is tried 3 times, 20 s apart. A rate limit without `Retry-After` waits 60 s. Any 429 that is not one of the three documented 429 codes is a rate limit (this narrows D-31), and a 404 at create stays ambiguous. Once any attempt was ambiguous, nothing here fails the generation: it keeps retrying, and at the window's end it is handed to PE-014. Otherwise the window's end fails it free by its last outcome | Doubling from a stored instant needs no counter column, and it holds an overloaded generation to about 9 creates in 2 min instead of 60. A 429 never creates anything, whatever its body says. A latched generation may already exist at Pixelforge and be billed, so failing it would lose the charge (D-09). |
| D-48 | Dev and E2E bounds come from the container environment, not `.env`. `docker-compose.yml` gives app, worker, scheduler and playwright `PIXELFORGE_RATE_LIMIT_PER_MINUTE` (600) and `PIXELFORGE_OVERLOADED_DEADLINE` (15) through one anchor, each defaulted with `${NAME:-…}`, and `.env.example` sets the same values for the CI image. Laravel's immutable dotenv keeps the container's value. Playwright derives its timeouts from the same variable, falling back to `.env.example` in CI | An existing dev `.env` lacks the new names and is never edited, yet `make e2e` runs against it. A developer's own `.env` value still wins, because Compose interpolates from it. Production sets neither, so it keeps the defaults. A test pins that the compose defaults equal `.env.example`. |

## Tests
| Test | Location | Covers |
| --- | --- | --- |
| `GenerationTest` (existing) | `tests/Unit/Domain/Generation/Models/` | Task 1: empty bookkeeping on a plain row, and the casts round-trip. |
| `SubmitBookkeepingTest` | `tests/Unit/Domain/Generation/ValueObjects/` | Task 2, pure:<br>- the first start sets the first and last attempt and nulls the outcome; a later start moves only the last attempt;<br>- a start after an attempt with no outcome latches at that attempt's start;<br>- the overload and concurrency anchors are set once;<br>- `ambiguous` latches once and the latch never clears;<br>- the 401 count increments;<br>- the note follows `retryNote()` and survives `accepted`;<br>- `handOver` sets the latch if it is missing, the hand-off time and the `retrying` note;<br>- the `isAmbiguous` truth table. |
| `ThrottledPixelforgeClientTest` | `tests/Unit/Support/Pixelforge/` | Task 3, over an `ArrayStore` and a recording inner client, with a limit of 3:<br>- three creates reach the inner client;<br>- the 4th throws with `retryAfterSeconds` = 60 and reaches nothing;<br>- 20 s later the wait reads 40;<br>- after 60 s, a create passes again;<br>- `isConfigured()` takes no unit. |
| `PixelforgeConfigTest` (existing) | `tests/Feature/Pixelforge/` | Tasks 3, 6, 10, 13, 15: each key's default, a set value, and non-positive or non-integer values falling back to the default. |
| `LocalEnvTemplateTest` (existing) | `tests/Unit/` | Tasks 3 and 18: `.env.example` holds 600 and 15, and `docker-compose.yml` contains `${NAME:-<that value>}` for each. |
| `HttpPixelforgeClientTest`, `PixelforgeApiExceptionTest` (existing) | `tests/Feature/Pixelforge/`, `tests/Unit/Support/Pixelforge/Exceptions/` | Task 4 rows, as described in task 4. |
| `GenerationFailureCodeTest` (existing) | `tests/Unit/Domain/Generation/Enums/` | Tasks 5 and 14: each new code's copy; every `LocalFailureReason` is still a case. |
| `SubmitRetryPolicyTest` | `tests/Unit/Domain/Generation/ValueObjects/` | Tasks 6 and 13–15, one row per Retry policy cell, built from `SubmitBookkeeping`, with fixed bounds and a fixed `now`:<br>- Retry-After 12 → 12; none → 60;<br>- the budget wait;<br>- 503 waits 2, 2, 4, 8, 16 and 30 at 0, 2, 4, 8, 16 and 32 s after the first 503, shortened to the deadline but never below Retry-After, failing at the deadline but retrying when latched;<br>- ambiguous waits 5 → 60;<br>- the window turns a retry into a stop;<br>- `hasWindowEnded` at window − 1 s and at the window;<br>- concurrency 5 s and the max wait (task 13);<br>- daily at once, or 60 s when latched (task 14);<br>- 401 at 1, 2 and 3, and latched (task 15). |
| `StopSubmittingGenerationActionTest` | `tests/Unit/Domain/Generation/Actions/` | Task 7:<br>- each last outcome gives its window-end reason, failed with provider cost 0, user charge 0, a single release and no charge entry;<br>- never ran gives `service_unavailable`;<br>- latched gives `pending`, the hold kept, no ledger change, and the hand-off, latch and note set;<br>- an attempt with no outcome is also handed over;<br>- a second stop writes nothing new (AC 15's second failure path);<br>- a `queued` or terminal row is untouched. |
| `SubmitGenerationRejectionsTest` (existing) | `tests/Feature/Jobs/` | Task 9: `retriedFailures` becomes `releasedFailures`, with the expected delays:<br>- 401 → 60, then 20 from task 15;<br>- 404 → 5;<br>- 429 rate with Retry-After 17 → 17; concurrency → 5;<br>- daily → 60, then failed from task 14;<br>- 503 → 2; no key → 60;<br>- timeout, 422 cut off, 502 HTML, a 2xx that is not a generation, and a 400 holding a NUL → 5;<br>- status, costs and ledger are unchanged throughout.<br>Also the AC 15 deadline row: `A lighthouse at dusk [overloaded]` 503s across a 120 s travel are failed free with `provider_overloaded`, ledger `[hold −86,318,750, release +86,318,750]`, net 0. Task 10: `failed()` stops the generation. |
| `SubmitGenerationDispatchTest` (existing) | `tests/Feature/Jobs/` | Task 10:<br>- the payload has `maxTries` null, backoff `5,15,30,60`, `timeout` = `submit.job_timeout_seconds`, and `retryUntil` = now + 1,800 + 600;<br>- AC 19: request timeout < job timeout < `queue.connections.database.retry_after`, with connect ≤ request, all read from config. |
| `SubmitGenerationWorkerTest` | `tests/Feature/Jobs/` | Task 10, AC 17, on the real `database` queue with `queue:work database --once --tries=3`:<br>- across five 503 attempts, travelling past each release, the job is still in `jobs` and `failed_jobs` is empty;<br>- past `retryUntil`, the next run fails the job, and `failed()` leaves the generation failed free (`provider_overloaded`). |
| `SubmitGenerationRateBudgetTest` | `tests/Feature/Jobs/` | Task 11:<br>- AC 1: with a limit of 1, one job run sends, then a second run for another generation sends nothing after `Cache::forgetDriver('database')` (a fresh store, as in another process) and is released ≤ 60 s; the count is a row in the `cache` table;<br>- AC 2: with the default limit, 50 units taken, the job sends nothing, stays `pending` and is released between 1 and 60 s; after that wait it sends;<br>- many budget waits never fail it before the window, and the window's end gives `provider_busy`. |
| `SubmitGenerationRetriesTest` | `tests/Feature/Jobs/` | Task 12; the rows are listed after this table. |
| `SubmitGenerationLimitsTest` | `tests/Feature/Jobs/` | Tasks 13–15; the rows are listed after this table. |
| `GenerationHistoryStatusTest`, `GenerationHistoryPageTest` (existing) | `tests/Feature/Generation/` | Tasks 5 and 14: a locally failed row of each new reason has its message and a null charge. Task 16:<br>- `pending` with `retrying` → "Pixelforge is busy — retrying automatically";<br>- `pending` with `waiting_for_slot` → "Waiting for a free slot";<br>- a `queued` or `failed` row with a stale note → null;<br>- `pending` with no note → null;<br>- `ITEM_KEYS` gains `status_note`. |
| `HistoryItem.test.ts` (existing) | `tests/js/integration/components/history/` | Task 17:<br>- the retrying and the waiting card show the Queued badge, the note text, `Est. …` and no `[data-charge]`;<br>- each of the four new failure messages shows Failed, "Not charged" and `Est. …` (AC 21);<br>- a note holding `<b>x</b>` renders literally;<br>- a null note renders no `[data-status-note]`. |
| `live-status.spec.ts` (existing) | `tests/e2e/` | Task 18, AC 22, on desktop and mobile, with the test's timeout raised to cover `OVERLOADED_FAILURE_TIMEOUT_MS`:<br>- prompt `A lighthouse at dusk [overloaded]` (Medium, Square);<br>- within `RETRY_NOTE_TIMEOUT_MS`, the Queued badge and "Pixelforge is busy — retrying automatically";<br>- at a 375 px viewport, the card sits inside its box (`expectInsideContentBox` over the badge, prompt, note and estimate) with no horizontal scroll;<br>- then, within `OVERLOADED_FAILURE_TIMEOUT_MS`, Failed, the `provider_overloaded` copy, "Not charged" and `Est. $0.0582 – $0.0863`, with no note;<br>- `expectNoReload` throughout. |

`SubmitGenerationRetriesTest` rows (task 12). Each uses `Http::fake` sequences, with time travel between runs:
- **AC 10.** `503` then `202` gives two create requests with the same key and body, one `provider_id`, Queued, and the hold only. `503` then a replayed `succeeded` is charged once: a later `generation.succeeded` webhook changes nothing.
- **AC 16.** `A lighthouse at dusk`: `503`, then `202`, then `generation.succeeded` delivered twice with usage 5 / 0 / 1,100 / 1,500. It stores 62,775,000 and 78,468,750, and the ledger is exactly `[hold −86,287,500, release +86,287,500, charge −78,468,750]`, net −78,468,750.
- **AC 18.** `503`, then a timeout, then `202`. All three recorded requests share the method, URL and `Idempotency-Key` (the ULID), have `metadata.generation_id` equal to the ULID, and have identical bodies.
- **AC 12.**
  - Each ambiguous kind (a timeout, a connection refusal, 500, 502, 504, a 503 without `overloaded`, a 2xx that is not a generation) latches the generation, is released after 5 s, and is never failed.
  - A run finding an attempt with no outcome latches before it sends.
- **AC 13.**
  - Latched, then 503s past the overload deadline: released, not failed.
  - Latched at the window's end: `pending` with its hold, handed over, and History's note reads retrying.
- **AC 20.** After an ambiguous attempt, a `generation.processing` webhook arrives; the next run sends nothing and the row stays processing.
- **AC 23.** `503`, then a timeout, then a travel to the window's end. After each step, the test asserts the first and last attempt times, the outcome, the latch, the overload anchor, the hand-off (null until the last step) and the note.

`SubmitGenerationLimitsTest` rows (tasks 13–15):
- **Concurrency.** A concurrency 429 with `Retry-After: 5` is released after 5 s. The row keeps the `waiting_for_slot` note and the anchor, and History reads "Waiting for a free slot" (AC 5). Past 600 s since the first concurrency 429, it is failed free with `provider_busy` and one release (AC 7). Latched, it is released instead.
- **Daily.** It is failed free at once with `daily_limit` and one release. A second run sends nothing (AC 8). Latched, it is released after 60 s.
- **401.**
  - The 1st and 2nd 401s are released after 20 s. The 3rd is failed free with `service_unavailable`, with exactly one critical entry holding the ULID and `invalid_api_key` and no secrets (see Story clarifications).
  - Latched, the 3rd is released after 60 s with the one critical entry, and a 4th writes no second one (AC 14).

| AC | Proven by |
| --- | --- |
| 1–2 | `ThrottledPixelforgeClientTest`, `SubmitGenerationRateBudgetTest`, `SubmitRetryPolicyTest` |
| 3–4 | `SubmitRetryPolicyTest`, `SubmitGenerationRejectionsTest`, `HttpPixelforgeClientTest`, `PixelforgeApiExceptionTest`, `GenerationHistoryStatusTest` |
| 5–7 | `SubmitRetryPolicyTest`, `SubmitGenerationLimitsTest`, `GenerationHistoryStatusTest`, `HistoryItem.test.ts` |
| 8 | `SubmitGenerationLimitsTest`, `GenerationFailureCodeTest` |
| 9–11 | `SubmitRetryPolicyTest`, `SubmitGenerationRejectionsTest`, `SubmitGenerationRetriesTest` |
| 12–13 | `SubmitBookkeepingTest`, `SubmitRetryPolicyTest`, `SubmitGenerationRetriesTest`, `SubmitGenerationLimitsTest` |
| 14 | `SubmitGenerationLimitsTest` |
| 15 | `StopSubmittingGenerationActionTest`, `SubmitGenerationRejectionsTest` |
| 16, 18, 20 | `SubmitGenerationRetriesTest` |
| 17 | `SubmitRetryPolicyTest`, `StopSubmittingGenerationActionTest`, `SubmitGenerationWorkerTest`, `SubmitGenerationRateBudgetTest` |
| 19 | `SubmitGenerationDispatchTest` |
| 21 | `GenerationFailureCodeTest`, `GenerationHistoryStatusTest`, `HistoryItem.test.ts` |
| 22 | `live-status.spec.ts` |
| 23 | `SubmitBookkeepingTest`, `SubmitGenerationRetriesTest` |

## Size
The estimate is ≈ 3,400 changed lines, about 65 % of them tests. That is far above the ≈ 400 budget, and above the story's own ~550, which counted neither the policy tables nor the test churn of moving PE-009's job from rethrowing to releasing. It ships as one PR of self-contained commits, by the project rule.

| Tasks | Content | ≈ Lines |
| --- | --- | --- |
| 1–4 | Columns, bookkeeping, budget, undocumented 429 | 750 |
| 5–7 | Failure copy, retry policy, stop | 700 |
| 8–10 | Recording attempts, releasing, time bounds | 650 |
| 11–12 | Budget and sequence proofs | 450 |
| 13–15 | Concurrency, daily limit, 401 | 450 |
| 16–18 | Note to History, card, E2E | 330 |
| 19 | Decisions and README | 70 |

**Cut point: after task 12.** PR A (≈ 2,550 lines) is the story's PE-013a. It covers AC 1–4, 9–13, 15–20 and 23. In PR A a concurrency 429, a daily limit and a 401 are retried like a rate limit until the window ends. PR B (≈ 850 lines) is tasks 13–19.

## Risks
- **Leaked budget rows.** `HttpPixelforgeClientTest` has no RefreshDatabase. If it went through the binding, every create would leave a `cache` row in the test database and exhaust the budget for later tests. Task 3 points it at the concrete driver. Any new test that runs the job needs RefreshDatabase.
- **The in-process worker (task 10).** `queue:work --once` inside PHPUnit registers a `pcntl` alarm and pops inside RefreshDatabase's transaction. If that proves flaky, keep the payload assertions (`retryUntil` set, `maxTries` null) and drive `Worker::process()` with a `WorkerOptions` whose `maxTries` is 3, instead of the command.
- **Second precision.** Dates are stored to the second (`DateFormat`), so deadlines compare in whole seconds. Tests travel in whole seconds.
- **The fixed window.** At a minute boundary, up to 100 calls can land in a rolling minute (D-44). Our load (3 concurrent, 500 a day) is far below that, and the 429 path is the backstop.
- **Test churn in task 9.** PE-009's "rethrown" tests become "released" tests. The "row unchanged" checks must ignore `submit_*`, because those columns change by design.
- **Stale worker and containers.** A long-running worker keeps the old job code, and compose env changes need recreated containers. Follow the per-task commands above, or `make e2e` tests old code.
- **E2E time.** The AC 22 test takes about 15–25 s, with a derived timeout of about 52 s per project. If the parallel projects starve the single worker, raise `MARGIN` for this timeout only, and never add sleeps.
- **The hand-off is one-way here.** A handed-over generation stays `pending` with its hold, and History keeps polling it, until PE-014 resolves it. That is intended (AC 13).
