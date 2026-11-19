# Review — PE-009-pixelforge-client-and-submit-job

## Round 2

**Verdict: APPROVE**

Scope reviewed: the three fix commits `65c32ae`, `0251d57` and `f8cc926`, plus the bookkeeping commit `f705023`, on top of round 1's `1aa19c8`.

Checks run on HEAD (`chain-make.sh chain check`, exit 0):
- Pint and Larastan are clean.
- PHPUnit runs 916 tests with 4,472 assertions, all passing (11 more than round 1).
- ESLint, Prettier and vue-tsc are clean, and Vitest runs 107 tests, all passing.

### Round 1 findings, verified

- **W1 (fixed in `65c32ae`).**
  - `HttpPixelforgeClient.php:67` now catches `ConnectionException|RequestException`. I checked `PendingRequest::marshalTransportExceptionWithResponse()`: it throws a `RequestException` for a 4xx or 5xx response and a `ConnectionException` for anything else, so both cut-off cases are covered. No `->throw()` is configured on the request, so the new catch cannot hide a complete response. Complete responses still go through `tryFromResponse()`.
  - `transportFailed()` takes the common parent `HttpClientException` and keeps the status when a status line arrived. Its message stays fixed. The truncated body is kept only on the previous exception, and the key sentinel row for a 503 cut off shows that the key never reaches it.
  - The job's retried-failures matrix gains a 422 cut off mid-body. That row matters most: it proves that a definitive-looking status without a complete envelope is retried, not failed locally.
- **S1 (fixed in `0251d57`).** Both timeouts use `FILTER_VALIDATE_INT` with `min_range` 1 and a default. A word, `30s`, `0`, `-5` and `2.5` each fall back to the default.
- **S2 (fixed in `f8cc926`).**
  - `ReportsPixelforgeContext` extends `Throwable`, so it can be caught and thrown directly.
  - In `SubmitGenerationAction::execute()`, the `PixelforgeApiException` catch still comes first, so definitive rejections keep their own path. The second catch now covers the ambiguous and not-configured errors through the interface.
  - `failed()` checks the one type, and the give-up matrix gains a not-configured row.

### Commit hygiene

- There is one commit per finding, and each fix ships with its tests. The bookkeeping (`f705023`, the ticked `review.md`) has its own commit.
- All subjects start with `[PE-009]` and are at most 72 characters. Every message ends with the trailer.

No new findings.

## Round 1

**Verdict: REQUEST CHANGES**

Scope reviewed: the 16 `[PE-009]` commits `07976dd..1aa19c8` on `pe-009-pixelforge-client-and-submit-job`, against `story.md` and `plan.md`. They sit on top of PE-008's `350236a`. `git diff origin/main..HEAD` also shows PE-005 files as deleted, but only because PE-005 merged into `main` after this branch was cut. No `[PE-009]` commit touches them.

Checks run on HEAD (`chain-make.sh chain check`, exit 0):
- Pint and Larastan are clean.
- PHPUnit runs 905 tests with 4,433 assertions, all passing.
- ESLint, Prettier and vue-tsc are clean, and Vitest runs 107 tests, all passing.

One Warning blocks approval (W1): one transport failure gets past the typed contract. Everything else is sound.

### Summary

The implementation follows the plan class for class, and all 14 plan tasks are checked.

- **Contract and binding (AC 1, criterion #4):**
  - `PixelforgeClient` exposes one operation, typed in and out.
  - `AppServiceProvider` uses `bind`, not `singleton`, and resolves lazily, so an unknown driver such as `htp` fails only when the client is first needed, naming the value and `PIXELFORGE_DRIVER`.
  - Support never imports a Domain model. The Domain action builds the payload from the row.
- **Request (AC 2–5):**
  - `CreateGenerationPayloadData::toJson()` is a single `json_encode` with fixed flags and fixed key order. `reference_images` is left out when empty, and `metadata` holds only `generation_id`.
  - Tests decode the body rather than compare bytes, and they compare bytes across two attempts on a reloaded Unicode row, as the Technical Notes ask.
  - The header test strips `host`, `user-agent` and `content-length`, then asserts exactly the four application headers.
  - `withoutRedirecting()` is pinned by a 302 test.
- **Responses (AC 6–8):**
  - `PixelforgeApiException::tryFromResponse()` is a pure parser. It accepts only an exact envelope: the code's own status, string fields, a bounded `param`, string-list `errors` and no NUL byte (the deferred PE-006 audit item). It reads `Retry-After` only as 1–9 digits. The unit matrix covers every refusal.
  - The replayed flag is compared case-insensitively.
  - A 2xx that `GenerationSnapshot::from()` cannot read is ambiguous, and the parse error is kept as `previous`.
- **Key hygiene (AC 9–10):**
  - An empty key throws before any request is built.
  - `#[SensitiveParameter]` is on the constructor, and `phpunit.xml` forces the key to `''`. `Http::preventStrayRequests()` now runs in every test.
  - The sentinel is asserted absent from logs, `(string)` exceptions and the queued payload for a 202, a 401, a read timeout and a 503.
- **Dispatch (AC 11–12):**
  - `ShouldQueueAfterCommit` is proven on the real `database` queue: the job is pushed at transaction level 1, and nothing is queued after an outer rollback or after a failing hold.
  - The `DatabaseTruncation` test reads the committed row and hold from a second connection inside the fake, and truncates in teardown so no committed rows leak into later tests.
  - Timeouts nest and are pinned: 5 ≤ 30 < 60 < 90.
- **Job and action (AC 13–20):**
  - `SubmitGenerationAction` re-reads the row, guards on the provider id or a terminal status, and wraps only the client call in `try`. Every answer goes through `TransitionGenerationAction`.
  - The tests cover a replayed succeeded snapshot charged once with a later webhook adding nothing, a webhook delivered inside the fake callback that is not undone, and a lost response recovered by a replay. All amounts are exact ints.
  - 400, 422 and 409 fail at no charge with `[hold, release]`. The 422 and 409 each log an error. Every other failure is rethrown with the row unchanged.
  - `failed()` logs the give-up line and never touches the row.
- **Logs (AC 21):** every submission line's level and exact context are asserted. No line carries the key, the prompt or the body.
- **Decisions:** D-31, D-32 and D-33 follow D-30 with no gap and match what was built.
- **Style:**
  - `strict_types` everywhere. Every class is `final`, apart from the one intentionally abstract base. Every method has a return type, and there is no `else`.
  - Error codes are an enum, and the driver name and replay header are constants.
  - The job lives in `app/Jobs` and the Domain create action dispatches it. Both follow the plan and the story ("dispatched after commit by PE-006's create action"), so this deviation from guidelines §3 is acknowledged, not raised.

### Commit hygiene

- The plan (`07976dd`) and the checkboxes and status (`1aa19c8`) each have their own bookkeeping commit. The decisions rows have their own commit (`1449d30`), as plan task 14 specifies.
- There is one code commit per plan task (`46a9fcf`..`a6b887a`), and each carries its tests. Tasks 6, 8, 10 and 13 pin behaviour with tests (task 6 also adds the attribute), as planned.
- Every subject is at most 67 characters and starts with `[PE-009]`. All 16 messages end with the trailer.
- I ran the checks on HEAD only. By inspection, each intermediate commit is self-contained. Tasks 7–8 add the job without dispatching it, so the plan's cut point after task 6 still holds.

### Warnings

- [x] **W1. A transport failure after the response headers escapes the driver as `Illuminate\Http\Client\RequestException`, outside the typed contract.**
  - `app/Support/Pixelforge/Drivers/HttpPixelforgeClient.php:65` catches only `ConnectionException`.
  - With Guzzle 8.5, a cURL error after the status line has arrived becomes a `ResponseTimeoutException` (error 28) or a `ResponseTransferException` (for example a reset while the body is read). Examples: a 503 whose body stalls past the 30 s timeout, or a 429 cut off mid-body. Laravel's `PendingRequest::marshalTransportExceptionWithResponse()` (`vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php:2161-2168`) then throws `$response->toException()`, a `RequestException`, for any 4xx or 5xx status. It throws a `ConnectionException` only for a 2xx.
  - I confirmed this with a throwaway test, since deleted. An `Http::fake` callback threw `ResponseTimeoutException` carrying a `503` with a truncated body, and `createGeneration()` threw `Illuminate\Http\Client\RequestException`.
  - Why it matters:
    - The contract (`PixelforgeClient.php:24-26`) promises only the three Pixelforge exception families. AC 8 says any response without a documented envelope is ambiguous, and criterion #4 says domains never see `Http::` types.
    - `SubmitGenerationAction.php:60-74` does not catch it, so the warning line with `error_code` is skipped, and `SubmitGenerationJob::failed()` logs no code.
    - PE-013 will branch on the typed errors, and this case would bypass that policy.
    - The money invariant still holds today, because the job retries any throwable with the same key.
  - Fix:
    - In the driver, catch `ConnectionException|RequestException` and map both to `AmbiguousPixelforgeOutcomeException::transportFailed()`. Widen that factory's parameter to `Illuminate\Http\Client\HttpClientException`, the parent of both. Optionally, pass the response's status when one arrived.
    - Update the contract's and the factory's docblocks: "no complete response arrived".
    - Add rows to `HttpPixelforgeClientTest::test_a_transport_failure_is_ambiguous`, or a sibling test, with an `Http::fake` callback that throws `GuzzleHttp\Exception\ResponseTimeoutException` (and `ResponseTransferException`) carrying a `503` with a truncated body. Assert that the result is ambiguous and not a typed error.
    - Add the same case to the AC 10 sentinel matrix.
  - **Resolved in `65c32ae`.** The driver catches `ConnectionException|RequestException`, and `transportFailed()` takes an `HttpClientException` and keeps the status when a status line arrived. New rows: three cut-off responses in `HttpPixelforgeClientTest` (a 503 timeout, a 429 and a 422 reset), a 503 cut off in the key sentinel matrix, and a 422 cut off in the job's retried-failures matrix.

### Suggestions

None of these blocks QA.

- [x] **S1. A non-numeric timeout silently becomes "no timeout".**
  - `config/pixelforge.php:32,34` cast with `(int) (env(...) ?: default)`. An operator typo such as `PIXELFORGE_REQUEST_TIMEOUT=30s` gives 30, but `=thirty` gives `0`, and Guzzle treats `0` as "wait forever". The job's 60 s timeout still kills the worker, so D-33's no-overlap guarantee survives, but the HTTP-level bound is gone without any warning.
  - Fix: use the default for anything that is not a positive integer, for example `filter_var(env('PIXELFORGE_REQUEST_TIMEOUT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 30`. Add one `PixelforgeConfigTest` row.
  - **Resolved in `0251d57`.** Both timeouts use `FILTER_VALIDATE_INT` with `min_range` 1 and the default. `PixelforgeConfigTest` covers a word, `30s`, `0`, `-5` and `2.5`.
- [x] **S2. The "Pixelforge failure with `context()`" union is spelled out twice.**
  - `SubmitGenerationAction.php:118-121` types the three classes as a union, and `SubmitGenerationJob.php:51-53` repeats them as three `instanceof` checks. PE-010 and PE-013 will add cases.
  - Fix: a small `App\Support\Pixelforge\Contracts\ReportsPixelforgeContext` interface (`context(): array`), implemented by the three families. Both places then check one type. This is optional, because the current code is correct and tested.
  - **Resolved in `f8cc926`.** The interface extends `Throwable` and the three families implement it. The action's second catch, `loggedForRetry()` and `failed()` all use it. The give-up matrix gains a not-configured row.
