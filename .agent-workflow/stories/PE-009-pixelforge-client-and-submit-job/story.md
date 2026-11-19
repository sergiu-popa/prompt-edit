# Story: PE-009-pixelforge-client-and-submit-job

## Title
Pixelforge client contract and the idempotent submit job

## Status
`qa-ready`

## Description
Until now, generations exist only locally. This story sends them to Pixelforge safely. It serves three grading criteria:

- **#1:** a generation is never lost or created twice, so it is never billed twice.
- **#2:** every retry is safe.
- **#4:** domains depend on a typed contract, not on `Http::` and string codes.

Pixelforge sits behind a contract in `app/Support/Pixelforge`, with an HTTP driver for `POST /v1/generations`, typed DTOs and one typed error per documented code.

Each local generation is submitted from a queued job. Its ULID serves as both the `Idempotency-Key` and `metadata.generation_id` (D-05). The payload is rebuilt from stored columns on every attempt, so a retry sends identical params and Pixelforge replays the original instead of creating a second generation or answering 409.

The 202 or replayed response goes through the same transition action as webhooks (D-06), so a webhook that arrived first is never undone. A generation fails here, at no charge, only on a definitive rejection where Pixelforge created nothing for us. Every other outcome is retried with the same key.

## Acceptance Criteria
**Lighthouse** is the docs request: prompt `A lighthouse on a cliff at dawn, oil painting`, `high`, `1536x1024`, reference `https://example.com/style.png`. Its hold is −457,150,000 n$ (−$0.457150000). All tests use `Http::fake` with stray requests prevented.

**Client contract and HTTP driver**
1. With `PIXELFORGE_DRIVER` unset or set to `http`, the container resolves the Pixelforge client contract to the HTTP driver. An unknown value (e.g. `htp`) fails with a configuration error that names it.
2. A create call sends `POST {PIXELFORGE_BASE_URL}/generations` (default base `https://pixelforge.promptedit.dev/v1`) with these headers:
   - `Authorization: Bearer <PIXELFORGE_API_KEY>`
   - `Idempotency-Key: <generation ULID>`
   - `Content-Type: application/json`
   - `Accept: application/json`
3. For Lighthouse, the JSON body decodes to exactly `{"prompt": "A lighthouse on a cliff at dawn, oil painting", "size": "1536x1024", "quality": "high", "reference_images": ["https://example.com/style.png"], "metadata": {"generation_id": "<ULID>"}}`.
   - Size and quality are always sent.
   - `reference_images` is omitted when there are none.
   - No other key is ever sent, because Pixelforge answers unknown params with 422.
4. A Unicode prompt is sent unchanged. For `A lighthouse on a cliff at dawn, oil painting 🎨🎨🎨🎨` (50 code points), the decoded `prompt` equals the stored prompt.
5. Two attempts for the same generation send byte-identical bodies and the same key, even when the second attempt reloads the row from the DB.
6. A 202 returns the parsed snapshot (PE-006's DTO) and a `replayed` flag. The flag is true when the response carries `Idempotent-Replayed: true`, and false otherwise.
7. Each documented error envelope becomes a typed error. It carries the code (an enum), the `type`, the HTTP status and the message. It also carries `param` and `errors` when present, and `Retry-After` in seconds when that header is present. The documented cases are:
   - 400 `content_policy_violation`
   - 401 `invalid_api_key`
   - 404 `not_found`
   - 409 `idempotency_key_reused`
   - 422 `validation_failed`, with `param` `size` and `errors.size[0]` preserved
   - 429 `rate_limit_exceeded` with a faked `Retry-After: 17` → 17 (the docs give no fixed value)
   - 429 `concurrency_limit_exceeded` with `Retry-After: 5` → 5
   - 429 `daily_limit_exceeded` without `Retry-After` → none
   - 503 `overloaded` with `Retry-After: 2` → 2
8. These outcomes become a single retryable “ambiguous” error, never a definitive one:
   - a connect timeout or a read timeout
   - a refused connection
   - any response without a documented envelope (e.g. a 502 HTML page)
   - an undocumented error code
   - a 2xx whose body is not a generation object
9. When `PIXELFORGE_API_KEY` is empty, the driver raises a typed not-configured error and sends no request.
10. With a sentinel key value, the key appears in no log entry, exception message, serialized job payload or test output. This holds on a 401 and on a timeout too.

**Submit job**
11. Creating a generation dispatches the submit job, carrying only the ULID, after the row and its hold have committed. If the creating transaction rolls back, nothing is dispatched.
12. When the HTTP call is made, the generation row and its hold are already committed and readable from another DB connection.
13. Happy path: a 202 `queued` Lighthouse response leaves the generation `queued` with provider id `gen_…` stored. Its hold is untouched and there is no charge, so the net is −457,150,000 n$.
14. The job is a no-op, with no HTTP call, when the generation already has a provider id or is terminal.
15. Replay that has moved on: a replayed 202 carries a snapshot that is already `succeeded`, with usage 12 / 765 / 6,450 / 3,184.
    - It is applied through the transition action: provider cost 305,510,000 n$ and a single charge of 381,887,500 n$ ($0.381887500), for a net of −381,887,500 n$.
    - A later webhook with the same snapshot (PE-008) adds nothing.
16. Webhook before response: a succeeded webhook is applied while the create request is in flight (inside the `Http::fake` callback).
    - The 202 `queued` response that follows does not undo it.
    - The status stays `succeeded`, the provider id is kept and there is exactly one charge.
17. Lost response: the first attempt times out after Pixelforge accepted it, and the retry with the same key gets a replayed 202 for the same `gen_…`. The generation ends with that one provider id, its single hold and no duplicate ledger entry.
18. Definitive rejections fail the generation through the transition action with a local failure reason. The hold is released, so the Lighthouse net is 0 (−457,150,000 + 457,150,000), and the job is not retried:
    - 400 `content_policy_violation` gives the reason `content_policy_violation`.
    - 422 `validation_failed` gives the reason `validation_failed`. Pixelforge's message and `param` are kept for display (PE-012), and an error is logged, because our own validation missed it.
    - 409 `idempotency_key_reused` gives the reason `idempotency_key_reused`, and an error is logged as a bug signal.
19. Every other error is rethrown, so the job retries with the same key and the same body: 401, 404, every 429, 503, not-configured and ambiguous. Meanwhile the generation stays non-terminal and keeps its hold.
20. When the job runs out of attempts, the generation stays non-terminal with its hold, and the failure is logged with the ULID. It is never failed locally; PE-013 bounds the retries and PE-014 re-dispatches.
21. Submission log lines carry the ULID, the provider id when known, the error code and the replayed flag. They never carry the API key or the request body.

## Scope

### In Scope
- The Pixelforge client contract (create only), and its binding chosen by the configured driver.
- The HTTP driver, with the base URL, bearer key and connect/request timeouts in the Pixelforge config.
- Env var names, without values, in `.env.example`: `PIXELFORGE_DRIVER`, `PIXELFORGE_BASE_URL` and `PIXELFORGE_API_KEY`, plus the timeout names the Architect picks.
- A request payload DTO limited to documented fields and built deterministically from stored columns.
- A typed error per documented code (with a code enum), plus the retryable ambiguous error and the not-configured error.
- The submit job, dispatched after commit by PE-006's create action.
- The local failure-reason enum. PE-012's copy maps from it as its single source, and PE-013 appends to it.
- Failing definitive rejections (400, 422, 409) through the transition action.

### Out of Scope
- PE-013-provider-limits-retries-and-throttle owns the policy for 429, 503, 401, not-configured and timeouts. That covers Retry-After waits, bounded retries, failing free on the daily cap and a bad key, the proactive account-wide 60 rpm limiter and reading `X-RateLimit-*` headers. Until it lands, these errors retry on the job's default backoff.
- The fake driver: PE-010-fake-pixelforge-driver.
- `GET /v1/generations/{id}`: PE-014-reconciliation-poller.
- `GET /v1/usage`: PE-018-usage-reconciliation-and-final-signoff.
- The live smoke test against the real API: PE-015-production-go-live.
- The submit endpoint and its UI: PE-011-submit-generation-and-history.

## Technical Notes
- **Deferred from the PE-006 audit:** the error-envelope parser must refuse a NUL byte in `code`, `message` and `param` before it builds `LocalGenerationFailureData`. Postgres text would silently cut it. PE-006's `GenerationSnapshot::storable()` already does this for snapshots.
- **Depends on:**
  - PE-007-credit-ledger-hold-settle-release, for the hold and settlement.
  - PE-008-verified-idempotent-webhooks, because AC 15 and 16 apply a webhook.
- **Decisions:** D-05, D-06 and D-12. Requirements §2.1 (create params), §2.3 (idempotency), §2.4 (errors) and §4 (robustness matrix).
- **Pixelforge facts:**
  - Create params:
    - `prompt`: at most 4,000 chars
    - `size` and `quality`
    - `reference_images`: at most 4 http(s) URLs, not downloaded
    - `metadata`: at most 20 string pairs, values at most 500 chars

    Unknown params return 422. `Idempotency-Key` is at most 255 chars, so a 26-char ULID fits.
  - Same key with the same params: 202 with `Idempotent-Replayed: true`, returning the generation as it is now. Nothing is billed and it doesn't count against the concurrency or daily limits, but it does count toward the 60 rpm.
  - Same key with different params: 409.
  - Keys are account-scoped and never expire. That is why the key is the ULID and never an auto-increment id.
  - Error `type`s are `authentication_error`, `invalid_request_error`, `idempotency_error`, `rate_limit_error` and `api_error`. Branch on `code`.
- **The invariant:** fail locally only when Pixelforge definitively created nothing for this request. When in doubt, retry with the same key; a replay is free.
- **Why 409 is free but logged:** an earlier generation may exist under this key with other params, and Pixelforge may bill it. Its webhooks then reach PE-007's D-09 path: provider cost recorded, user not charged.
- **Exact bodies:** assert on the decoded body, plus byte equality across attempts, not on a hard-coded byte string. JSON encoders may escape `/` or non-ASCII characters, which is semantically identical.
- **Job hygiene:**
  - Serialize only the ULID.
  - Dispatch after commit, or a worker can read an uncommitted row.
  - Keep the HTTP timeout below the job timeout, so a hung request cannot overlap its own retry.
  - Tests use the `sync` queue.
- **Interim behaviour:** until PE-013 lands, a daily-cap or bad-key response keeps retrying and then leaves the generation non-terminal with its hold. This is acceptable because production has no Pixelforge key until PE-015.
- **Secrets:** tests and CI never reach the real API, and CI has no key. Never print, log or commit `PIXELFORGE_API_KEY`.
- **Required test layers:**
  - PHP unit: payload determinism (the field set, omitting empty refs, identical encoding) and the mapping from error envelope to typed error, if these are pure units.
  - PHP feature, HTTP driver against `Http::fake` (AC 1–10): headers, decoded body, every error code, the ambiguous cases, the not-configured error and key hygiene.
  - PHP feature, the job through the transition action and ledger (AC 11–21). AC 12 needs a test outside `RefreshDatabase`'s wrapping transaction, so a second connection can see committed rows.
  - JS unit, JS integration, E2E: none (no UI).
- **PR size:** about 750 changed lines with tests, well over the ~400 budget. Split:
  - PR 1: the contract, HTTP driver, DTOs and typed errors (AC 1–10).
  - PR 2: the submit job, dispatch, failure-reason enum and rejection handling (AC 11–21).

## Mockups / References
No UI.
- https://pixelforge.promptedit.dev/docs#create-generation
- https://pixelforge.promptedit.dev/docs#idempotency
- https://pixelforge.promptedit.dev/docs#errors
- https://pixelforge.promptedit.dev/docs#rate-limits
- https://pixelforge.promptedit.dev/docs#authentication
- requirements.md §2.1, §2.3, §2.4, §4, §5 (D-05, D-06, D-12)
