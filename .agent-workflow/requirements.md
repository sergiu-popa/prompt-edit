# Requirements analysis — PromptEdit take-home (Pixelforge)

Source of truth for every story in `.agent-workflow/stories/`. Architects and
engineers should read this before planning. Pixelforge facts below were taken
from https://pixelforge.promptedit.dev/docs and checked against the live API
on 2026-11-18 (`GET /v1/pricing`, `GET /v1/usage`, `GET /v1/generations`).

---

## 1. The brief in one paragraph

Signed-in users write a prompt, pick a size, a quality and up to 4 optional
reference image URLs, and see what it will cost **before** submitting. We
forward the request to Pixelforge (async, priced per token), track it live
(queued → processing → succeeded | failed), and show the image. Users pay
Pixelforge's cost **+25%**. The upfront price is an estimate; the actual cost is
recorded once the generation finishes. Failed generations are free. Webhooks
must be signature-verified, idempotent and order-independent — an event
delivered twice is never recorded twice. Deploy publicly and leave a few
generations on it.

Stretch: (a) an operator report — credit spend, provider cost, profit per day
and per user, with provider costs matching `GET /v1/usage`; (b) balances —
per-user credit balance, a fake "Buy credits" button, and generation is blocked
when the user can't afford it.

**Graded on, in order:** correct money → robustness → product sense/UX → code
quality. "A small app that records every cost exactly right beats a bigger one
that doesn't."

---

## 2. Pixelforge — the facts that matter

Base URL `https://pixelforge.promptedit.dev/v1`, `Authorization: Bearer <key>`,
JSON everywhere, ISO-8601 UTC timestamps, ids `gen_…` / `evt_…`, money as exact
decimal strings (USD). Webhook URL, signing secret (`whsec_…`) and usage report
are **per API key**; rate limits are **per account** (shared by all keys).

### 2.1 Endpoints

| Endpoint | Notes |
|---|---|
| `POST /v1/generations` | `202` + generation (`status: queued`). Body: `prompt` (req, ≤ 4,000 chars), `size` (`1024x1024` default, `1536x1024`, `1024x1536`), `quality` (`low`, `medium` default, `high`), `reference_images` (≤ 4 `http(s)` URLs, not downloaded), `metadata` (≤ 20 string→string, values ≤ 500 chars, echoed everywhere). **Unknown params → 422.** Optional `Idempotency-Key` header (≤ 255 chars). |
| `GET /v1/generations/{id}` | Current generation object. Other account's id → `404 not_found`. |
| `GET /v1/generations` | `limit` 1–100 (20), `status`, `cursor`; newest first; `{object:list, data, has_more, next_cursor}`. |
| `GET /v1/pricing` | Public. Rates + token tables (below). |
| `GET /v1/usage` | `start_date`/`end_date` `YYYY-MM-DD` UTC inclusive, default last 30 days, max span 92 days (`422 date_range_too_long`). One row per UTC day incl. zero days: `date, generations, input_text_tokens, input_image_tokens, output_tokens, reasoning_tokens, cost_usd` (9 decimals) + `total`. **Scoped to the calling key. A generation counts on the UTC day it *completed*.** "Source of truth for that key's provider costs." |

**Generation object:** `id, object, status, prompt, size, quality,
reference_images, metadata, image_url, image_url_expires_at, usage, rates,
error {code,message}, created_at, started_at, completed_at`.
`usage` (null until succeeded) = `input_text_tokens, input_image_tokens,
output_tokens, reasoning_tokens, total_tokens`. `rates` = the four rates as
strings, **fixed at creation** — bill with these, not with today's table.

### 2.2 Pricing (live `GET /v1/pricing`, per 1M tokens, USD)

| Token type | Rate | Known up front? |
|---|---|---|
| `input_text_tokens` | 5.00 | Estimate only: `ceil(chars / 4)`, actual within ±15 % |
| `input_image_tokens` | 10.00 | **Exact**: 765 × reference images |
| `output_tokens` | 40.00 | **Exact** by quality × size (table below) |
| `reasoning_tokens` | 12.50 | Range by quality, actual only after completion |

| Output tokens | 1024x1024 | 1536x1024 | 1024x1536 | Reasoning min–max |
|---|---|---|---|---|
| low | 280 | 420 | 420 | 0–500 |
| medium | 1,100 | 1,650 | 1,650 | 200–2,000 |
| high | 4,300 | 6,450 | 6,450 | 1,000–8,000 |

"Chars" are **Unicode characters (code points)** — PHP `mb_strlen()`,
JS `[...prompt].length` (**not** `prompt.length`, which counts UTF-16 units).
Test keywords count toward the prompt's characters.

Docs' worked maximum (high, 1536x1024, 400-char prompt, 2 refs, max text and
reasoning): 115 × 5 + 1,530 × 10 + 6,450 × 40 + 8,000 × 12.5 (per 1M)
= **$0.373875000** provider cost.

Billing: only `succeeded` is billed; `failed` and every rejected request
(400/409/422/429/503) cost nothing. "The API never returns a price." "Amounts
are never rounded … floating-point arithmetic will drift; use integers (for
example nanodollars) or decimals."

### 2.3 Lifecycle, timing, idempotency

- `queued → processing → succeeded | failed`; terminal states are final.
  Queue: a few seconds. Processing: 5–30 s (`[slow]`: 60–90 s).
- Same `Idempotency-Key` + same params → original generation **as it is now**
  (status may have moved on), `202` + `Idempotent-Replayed: true`, nothing billed,
  not counted against concurrency/daily limits (still counts toward rpm).
  Same key + different params → `409 idempotency_key_reused`.
  **Keys are account-scoped and never expire.**

### 2.4 Errors and limits

Envelope `{error: {type, code, message, param?, errors?}}` — branch on `code`.

| Code | HTTP | Meaning / header |
|---|---|---|
| `invalid_api_key` | 401 | Missing, malformed, revoked key |
| `content_policy_violation` | 400 | Prompt rejected; nothing created |
| `validation_failed` | 422 | Bad/unknown param; `param` + `errors` |
| `not_found` | 404 | Unknown id / other account |
| `idempotency_key_reused` | 409 | Same key, different params |
| `rate_limit_exceeded` | 429 | > 60 requests/min/account; `Retry-After` |
| `concurrency_limit_exceeded` | 429 | > 3 queued+processing; `Retry-After: 5` |
| `daily_limit_exceeded` | 429 | > 500 generations / UTC day; no `Retry-After` |
| `overloaded` | 503 | `Retry-After: 2`; nothing created; retry with the same idempotency key |
| `date_range_too_long` | 422 | Usage range > 92 days |

Failed generation `error.code`: `generation_failed` (model couldn't produce an
image) or `internal_error` ("You were not charged; please retry").
Every authenticated response carries `X-RateLimit-Limit` / `X-RateLimit-Remaining`.
Polling counts toward the 60 rpm. Unprompted chaos: a small share of
generations fail at random, a small share of creates return `503`, some
webhooks are delivered twice.

### 2.5 Webhooks

- Per-key URL set in the dashboard; must be public (no localhost/10.x), no
  redirects followed, no CSRF token → route outside CSRF protection.
- Events: `generation.processing`, `generation.succeeded`, `generation.failed`,
  `ping` (dashboard "Send test event"). **No `queued` event.**
- Envelope: `{id: evt_…, object: event, type, created_at, data: {object: <generation snapshot>}}`.
- Headers: `Pixelforge-Event-Id`, `Pixelforge-Event-Type`,
  `Pixelforge-Delivery-Attempt`, `Pixelforge-Signature: t=<unix>,v1=<hex>`.
- Signature: `hex(HMAC-SHA256(secret, "<t>.<raw body>"))`, compare with
  `hash_equals`, reject `|now − t| > 300 s`. Raw bytes — before JSON decoding.
  Fresh `t` per attempt.
- Success = any 2xx within 10 s; otherwise retried at 10 s, 30 s, 2 m, 10 m,
  15 m (6 attempts total), then given up → **poll as fallback**.
- **At least once, any order.** Duplicates share the event `id`. A retried
  `processing` can land after `succeeded`. A webhook can land **before** the
  `POST /v1/generations` response returns. No sequence number.

### 2.6 Images

`image_url` is a signed PNG URL that dies 24 h after completion
(`image_url_expires_at`); refetching does not extend it. Expiry is acceptable.

### 2.7 Test keywords (case-insensitive, anywhere in the prompt)

`[blocked]` → 400 at submit · `[overloaded]` → 503 at submit · `[fail]` →
accepted then `generation.failed` · `[succeed]` → never fails at random ·
`[slow]` → 60–90 s processing · `[duplicate]` → every webhook delivered twice.

---

## 3. Money model

**Unit: integer nanodollars (n$, 10⁻⁹ USD), `BIGINT`.** All four published
rates have ≤ 3 decimals, so a rate per token in n$ is an integer
(`"12.50"` per 1M → 12,500 n$/token): provider cost is exact integer
arithmetic and matches `cost_usd` (9 decimals) digit for digit. Rates are
parsed from their decimal strings with `brick/math` (already a framework
dependency) — never through `float`. A rate that would not convert to a whole
n$/token must throw, not round silently.

| Quantity | Formula |
|---|---|
| Provider cost | Σ tokensᵢ × rateᵢ(n$/token), rates from the generation's own `rates` |
| User charge | `ceil(provider × 125 / 100)` — round **up** to the next n$ (≤ 0.75 n$ in our favour; we never under-charge) |
| Text tokens (estimate) | `e = ceil(codepoints / 4)`; bounds `floor(e × 0.85)` … `ceil(e × 1.15)` (deliberately conservative by ≤ 1 token) |
| Estimate | min, typical and max user price from the bounds above, the exact image/output tokens and the reasoning range (typical = midpoint) |
| Failed | provider 0, user 0 |

Display: UI shows USD to 4 decimals (`$0.3739`) with the exact 9-decimal
value available (tooltip / admin report). Never round stored amounts.

The estimate is computed in **two places** (PHP authoritative, TS for instant
feedback). Both implementations run against one shared JSON fixture file of
cases so they cannot drift.

---

## 4. Robustness matrix

| Situation | Handling | User sees |
|---|---|---|
| Create `202` (or replay) | Store `provider_id`, apply returned snapshot | Queued |
| Webhook before `POST` returns | Local row exists *before* submit; match on `metadata.generation_id` (our ULID) | — |
| `400 content_policy_violation` / `[blocked]` | Mark failed, free | "Prompt rejected by Pixelforge's content policy" |
| `422 validation_failed` | Mark failed, free, log (our validation missed it) | Field message |
| `409 idempotency_key_reused` | Mark failed, free, log error (bug) | Generic failure |
| `429 rate_limit_exceeded` (or any 429 without a documented 429 code) | Release the job after `Retry-After` (else 60 s); every call first takes from one shared budget of 50 a minute (D-44); only the submit window (30 min) ends it, as `provider_busy` | Queued, "Pixelforge is busy — retrying automatically" |
| `429 concurrency_limit_exceeded` | Release after `Retry-After` (5 s); 10 min after the first one, fail free (`provider_busy`) | "Waiting for a free slot" |
| `429 daily_limit_exceeded` | Fail free at once (`daily_limit`), no further call | "Daily capacity reached, try after 00:00 UTC. You were not charged." |
| `503 overloaded` / `[overloaded]` | Retry with the **same idempotency key** and body, `Retry-After` as a floor, doubling to 30 s; 2 min after the first 503, fail free (`provider_overloaded`) (D-47) | Queued, "Pixelforge is busy — retrying automatically", then "Pixelforge was overloaded and didn't accept this request. You were not charged." |
| Timeouts, connection errors, other 5xx, an unreadable 2xx, a job that died mid-request | Ambiguous: retry with the same key, 5 s up to 60 s, and latch the generation; once latched nothing here fails it, and at the window's end it is handed to the reconciler pending with its hold (D-45, D-47) | Queued, "Pixelforge is busy — retrying automatically" |
| `401 invalid_api_key` | 3 tries, 20 s apart, then fail free (`service_unavailable`); one critical log per generation, without the key or any header | "Service unavailable — this generation wasn't sent. You were not charged." |
| `generation.failed` / `[fail]` | Failed, free, store `error` | Friendly text per `error.code` |
| Duplicate event (`[duplicate]`) | Unique `event_id` row inserted in the same transaction as the state change → second delivery is a no-op `200` | — |
| Out-of-order event | Forward-only state machine under row lock; terminal never changes; late `processing` ignored | — |
| Charge recorded twice | Impossible: status guard under `lockForUpdate` **and** a DB unique constraint on the ledger entry per generation | — |
| Webhooks never arrive | Every minute, a scheduled reconciler polls confirmed generations with no news for 2 min (`queued`) or 3 min (`processing`), judged by `last_provider_update_at`, through the **same** transition action, so money settles exactly once. At most 20 a run, oldest first, always leaving 10 budget units to submits; each row's next check waits a quarter of its age (1–15 min). `not_found` is logged and the row kept; a confirmed generation is never failed locally, and a day of `not_found` is logged once for the operator (D-49–D-51, D-54) | Status catches up; after 15 min, "Taking longer than usual — we're checking with Pixelforge" (D-56) |
| Worker died before submit, or a submit handed over as ambiguous | Reconciler re-dispatches the same submit job with the same ULID, once its last attempt (else its creation) is 5 min old, or at once when handed over; the job is owed one attempt past its window, and one create lock keeps a single create in flight (D-52, D-53). A generation Pixelforge never confirmed is failed `lost`, free, a day after creation; a late success records provider cost only (D-49, D-09) | Queued; then "We couldn't confirm this generation with Pixelforge. You were not charged." |
| Image URL expired | `image_url_expires_at` in the past → placeholder | "Image expired" |

**Idempotency key = our generation ULID**, also sent as
`metadata.generation_id`. Never an auto-increment id: Pixelforge keys never
expire, so a `migrate:fresh` would replay old generations. The request payload
is derived deterministically from stored columns so retries send identical
params (else `409`).

---

## 5. Decisions log

Short, interview-ready rationale. Add rows as stories make new decisions.

| # | Decision | Why |
|---|---|---|
| D-01 | Laravel 13 + Inertia + Vue 3 + TypeScript, Postgres 17, Tailwind 4 | Matches PromptEdit's stack. |
| D-02 | Minimal hand-built UI (Fortify for auth), visual language inspired by Flux | Small, reviewable diffs; every line explainable. No Flux code copied (licensed). |
| D-03 | Integer nanodollars | Exact; matches `/v1/usage` 9-decimal strings; no float drift. |
| D-04 | Markup rounds **up** to the next n$ | Never under-charge; error ≤ 0.75 n$ per generation. |
| D-05 | Local row before submit; ULID = idempotency key = `metadata.generation_id` | Survives webhook-before-response, retries, DB resets. |
| D-06 | One forward-only transition action used by webhooks, the create response and the poller | Single code path → one place to prove exactly-once. |
| D-07 | Webhook events processed inline in one transaction with a unique `event_id` row | DB-only work finishes far inside 10 s; dedupe and state change commit or roll back together. |
| D-08 | Append-only credit ledger from the first money story; balances build on it | "Never recorded twice" enforced by a unique constraint, not by convention. |
| D-09 | Provider cost is recorded for every provider-`succeeded` generation, even if the user is not charged | The admin report must match `/v1/usage` exactly. |
| D-10 | Admin report groups by Pixelforge's `completed_at` UTC date | That is how `/v1/usage` buckets costs. |
| D-11 | Live status via Inertia polling of our own server (not Pixelforge) while anything is non-terminal | No websocket infra; Pixelforge rpm untouched by the UI. |
| D-12 | In-app fake Pixelforge driver (signed webhooks, keywords) for local + E2E; opt-in live smoke against the real API | Deterministic CI; real behaviour still verified. |
| D-13 | ~~Deploy on Laravel Cloud (web + queue worker + scheduler, Postgres)~~ **Superseded by D-19 (2026-11-19).** | The user deploys on their own server instead. |
| D-14 | Upfront hold = **max** estimate; settle actual on success, release on failure | Users can never overspend; actual ≤ max by construction. |
| D-15 | Estimates read a pinned `/v1/pricing` snapshot (`config/pricing.php`, checked 2026-11-18), never fetched at runtime. Live `text_token_variance: 0.15` is held as the integer `text_token_variance_percent: 15` | Keeps PHP, TS and the shared fixture deterministic. Only the estimate depends on it: settlement uses each generation's own `rates`, so a stale snapshot cannot mis-charge. PE-015's live smoke detects drift. A deliberate deviation from the docs' advice to read `/v1/pricing`. |
| D-16 | An estimate's typical count per token type is the midpoint of its min and max, rounded **up** to a whole token; typical is priced like the bounds, with the markup applied once to each total | One integer rule both languages share. With today's tables every midpoint is whole, and the text midpoint is always exactly `e`. |
| D-17 | Login throttling is Fortify's pipeline step `EnsureLoginIsNotThrottled` (5 failures per minute per email + IP, cleared on success), not a route rate limiter | Successful sign-ins never count, so parallel E2E and real users can't lock themselves out. A lockout shows inline under Email instead of a 429 page. |
| D-18 | Emails are lowercased by this app at both entry points: an explicit login pipeline, and `CreateNewUser` before its uniqueness check, once the value is known to be a string. Fortify's `lowercase_usernames` is off | Postgres compares strings case-sensitively, so `Ada@…` and `ada@…` would be two accounts. Fortify v1.43's flag also lowercases at registration, but it does so before validation, so a crafted `email[]` answers 500. |
| D-19 | Deploy on the user's HestiaCP droplet at `https://prompt.sergiupopa.dev` with Deployer (`deploy.php`, modelled on the user's `yum` project). PostgreSQL 17 (PGDG) on the droplet, a systemd queue worker, a cron `schedule:run`. The assistant runs `dep deploy` after each merge, once CI on `main` is green, then smoke-checks production | The user's own server and their proven Deployer setup. Same database engine as dev and CI. Deploys stay gated on CI and observed by a smoke test. |
| D-20 | Database driver for queue, cache and sessions in every environment; no Redis | Nothing extra to run or secure on a shared droplet. The load is tiny (3 concurrent jobs, 500/day). Dev matches production. The database cache store supports the atomic locks and rate limiters later stories need. |
| D-21 | No trusted proxies in production. Hestia's nginx → Apache (HTTPS vhost, mod_remoteip on `X-Real-IP`) gives PHP `HTTPS=on` and the visitor's `REMOTE_ADDR`, so Laravel ignores every forwarded header. The chain does hand PHP a client's `X-Forwarded-Host` as the Host (found in the PE-004 audit), so `trustHosts()` limits the app to APP_URL's host and its subdomains | It is the narrowest setting. A client can't spoof the scheme, the host or the IP the sign-in throttle keys on. `trustProxies(at: '*')` would let any visitor choose their throttle bucket. |
| D-22 | The nightly backup is a scheduled artisan command (`app:backup-database`: `pg_dump -Fc` at 04:30 UTC into `private/backups/`, seven weekday files, credentials via `PGPASSWORD`), not a second cron | It is one schedule to maintain, and it runs before Hestia's 05:10 backup, which carries it. It is testable with `Process::fake()`, keeps the password off argv and out of the logs, and runs only where `DB_BACKUP_PATH` is set. |
| D-23 | The account command (`app:upsert-user`) reads the password from stdin | On a shared droplet, argv is readable by every local user (`ps`, `/proc`), and an option would also land in the server's shell history. The smoke's `E2E_REVIEWER_PASSWORD` pipes straight in. |
| D-24 | The generation lifecycle is a string-backed `GenerationStatus` enum with a numeric rank (pending < queued < processing < succeeded = failed), applied by one `TransitionGenerationAction`. It reads the row under `lockForUpdate` and writes with an UPDATE conditional on the status it read. `spatie/laravel-model-states` is not used | It adds no dependency. The rule is one rank comparison plus a terminal check, and a table test proves it for every pair. The conditional write means even a missed lock cannot double-apply. Model-states' transition classes add nothing this needs. |
| D-25 | Only `resources/js/lib/money.ts` formats money in the UI. Amounts show 4 decimals rounded half away from zero, and any non-zero amount under $0.00005 shows `<$0.0001`. The exact 9-decimal value is always reachable by hover, focus or tap. An ESLint rule rejects `toFixed` and currency-style `Intl` formatting anywhere else in `resources/js`. Later PHP displays (PE-016) use the same rule | Display rounding is a convenience and never the charged amount. A non-zero cost never reads as free. One formatter means one rounding rule. |
| D-26 | A transition's release and charge are inserted with `ON CONFLICT DO NOTHING` (Eloquent `fillAndInsertOrIgnore`), and an ignored insert is logged at error level. The hold uses a plain insert | Postgres aborts the whole transaction on a unique violation, so a duplicate would roll back the status change and turn a webhook into a 500. A savepoint per insert adds round trips for the same result. In normal operation the status guard makes a conflict impossible, so one firing means a guard was bypassed, and the log makes it visible. A duplicate hold on a row that was just created is a bug and should fail loudly. |
| D-27 | A snapshot without `metadata.generation_id` is identified by a stored provider id equal to its `id`, with a warning. Our ULID is required only while no provider id is stored. A present `generation_id` that differs from the row's ULID is still refused. This narrows PE-006 AC12 | The stored provider id is unique, and it was itself written from a ULID-matched snapshot, so it proves identity just as well. Refusing would strand a generation that Pixelforge bills, with its hold, for good, because PE-014 polls rows with a provider id until they are terminal. A contradicting id is still refused: then the data is wrong, not just incomplete. |
| D-28 | Webhook responses: a verified event for an unknown generation or of an unknown type gets 2xx and is recorded. A bad signature, or a signed body we cannot read, gets 400 and nothing is persisted. A missing secret or an unexpected failure gets 500 and rolls back | Pixelforge retries every non-2xx 5 more times over 27 min 40 s. The row always exists before submit (D-05), so for an unknown generation or type a retry can never succeed. An unreadable body is not recorded, so a dashboard resend after a fix is processed. A 500 is retried because a retry can help (a transient failure), or because the operator must act (a missing secret). |
| D-29 | Webhook dedupe and routing use the signed body's `id` and `type`. The `Pixelforge-Event-Id` and `Pixelforge-Event-Type` headers are informational: a disagreement is logged, and the body wins | Only the body is covered by the HMAC. A replayed delivery with rewritten headers could otherwise dodge dedupe or be routed as another type. |
| D-30 | Every verified webhook event id is recorded, including pings, unknown types and unknown generations, in the same transaction as its state change (D-07) | It is one dedupe rule with no exceptions. A redelivery is always a no-op and never repeats a warning about the event itself. Only a header that disagrees with the signed body is logged again, on every delivery, because a replay with rewritten headers is what an operator wants to see. The rows show what Pixelforge actually sent, including the dashboard's test ping. |
| D-31 | A Pixelforge error becomes a typed error only when its envelope is documented: a known `code` with that code's HTTP status, string `type`, `code` and `message`, no NUL byte in `code`, `message` or `param`, and a `param` of at most 255 characters. Everything else is one ambiguous error, retried with the same key: a transport failure or timeout, any other body, an undocumented code, a mismatched status, or a 2xx that is not a generation object. Only `400`, `422` and `409` fail a generation locally, at no charge | Fail locally only when Pixelforge definitely created nothing, because a replay is free. A create that timed out may have been accepted and billed, and failing it would lose the charge (D-09). Postgres text cuts at a NUL, so a stored reason is never silently truncated. |
| D-32 | Submission is a queued job that carries only the ULID. The create action dispatches it, and the queue releases it only after the outermost commit (`ShouldQueueAfterCommit`). Each attempt re-reads the row, rebuilds the body from stored columns with one fixed JSON encoding, sends it outside any transaction or row lock, and applies the answer through the transition action | A worker never reads an uncommitted row, and a caller's rollback submits nothing. Every retry sends byte-identical params, so Pixelforge replays the original instead of answering 409. A webhook that lands during the call is neither blocked nor undone (D-06). |
| D-33 | Timeouts nest: connect 5 s and request 30 s, below the job's 60 s, below the database queue's `retry_after` of 90 s, and a test pins the order. A `PIXELFORGE_REQUEST_TIMEOUT` at or above the job's timeout means the default, so the order holds whatever is set. Redirects are never followed. ~~Until PE-013, the job tries 5 times with backoff of 5, 15, 30 and 60 s. When it gives up, the generation stays non-terminal with its hold, and the give-up is logged with the ULID~~ **The interim tries and backoff are superseded by D-46 (2026-11-20).** | A hung request can never overlap its own retry. Following a redirect would re-send the key and the body somewhere we never configured. Stopping without failing keeps the invariant: PE-013 bounds retries by time, and PE-014 re-dispatches. |
| D-34 | Local dev and E2E use an in-app fake Pixelforge (`PIXELFORGE_DRIVER=fake`, the `.env.example` default; the config default stays `http`). It implements the client contract and keeps its state in its own `fake_pixelforge_generations` table. Delayed jobs on the normal database queue advance each generation, and it POSTs webhooks signed by PE-008's signer to the in-network `http://app:8080`. The app refuses to boot with it in production | It is deterministic, free and reachable without a public URL (D-12). Every local run still goes through signature checks, dedupe and the ledger. The table is shared by web, worker and E2E without Redis (D-20). |
| D-35 | The fake's usage is drawn within the PE-002 estimate's own bounds, seeded by the idempotency key. Rejections come first and store nothing: `[blocked]`, then `[overloaded]`, then a chaos 503. Idempotency is next. The outcome is `[fail]`, then `[succeed]`, then a chaos `internal_error`. `[slow]`, `[duplicate]` and `[expire]` combine with any accepted outcome. Chaos is off by default and rolled once per generation at create, not per webhook. `[succeed]` suppresses only a random failure, not a chaos 503 or duplicates, and a stored key resent with new params that hold a rejection keyword gets the rejection, not 409 | Usage the estimator itself bounds can never charge more than the hold, and the seed makes test amounts exact. A rejected request stores no key, so a retry is rejected the same way. The real API's order is unknown; PE-015's live smoke test guards against drift. |
| D-36 | Fake image URLs are root-relative temporary signed routes, served only while the fake is active, and an expired one answers 403. `make setup` and the CI image fill an empty `PIXELFORGE_WEBHOOK_SECRET` with 64 random hex characters, never a `whsec_` value. PHPUnit forces the driver to `http` and the secret empty | Dev browses `prompt-edit.localhost` and E2E browses `127.0.0.1:8080` on the same containers, so only a host-less URL loads in both. A signed URL dies like Pixelforge's. A local secret is never a real dashboard secret, and no test sees a developer's. |
| D-37 | The prompt is exempt from `TrimStrings`. The page, the estimate, the stored row, the hold and the Pixelforge request all use it exactly as typed. A whitespace-only prompt, by JS `trim()`'s set (ASCII whitespace, the Unicode `Z` categories and U+FEFF), is rejected on both sides. Reference URLs are trimmed on both sides | Laravel's `Str::trim` strips more than JS `trim()` (zero-width and other invisible characters), so trimming on both sides would count two different strings. Kept spaces cost a token or two at most, and the price shown is the price held. Whitespace around a URL never changes the price, which counts references. |
| D-38 | Double submit: the Generate page makes a UUID `submission_token` when it mounts and sends it with the form. `CreateGenerationAction` creates with `createOrFirst` on a unique `(user_id, submission_token)`. A replay returns the first generation with no second hold or job, and lands on History like the first | The database decides, so it holds for a double click, a resent request, a lost response and two concurrent requests. A new visit to Generate mounts a new token, so a deliberate repeat is a new generation. Scoping the token by user means one user can never collide with another's. The ULID stays server-made, because it is Pixelforge's idempotency key (D-05). |
| D-39 | Submissions are throttled per user by a named rate limiter: 10 a minute by default, `GENERATION_SUBMISSIONS_PER_MINUTE`. Over the limit, its response is a redirect back with a form-level `submission` error that gives the seconds from `Retry-After` | Inertia shows a bare 429 as an error modal. A validation-style error keeps the user on Generate with their input and an inline message. It also caps how fast one account can spend before PE-017's balances. |
| D-40 | Generate stays closed until Pixelforge is configured. Each driver reports `isConfigured()`: a key for `http`, a webhook secret for `fake`. Until then the page shows a notice and a disabled button, and a POST is refused with a form-level error before anything is written | Production has no key until PE-015. A generation accepted then could never be submitted, and it would sit pending with its hold until PE-014. |
| D-41 | History updates through a small composable, `useLiveUpdates`, over `router.reload({ only: ['generations'] })`, not Inertia's `usePoll`. It runs only while a visible generation is non-terminal, waits 3 s from the end of one poll to the start of the next, and never overlaps requests. After a failed poll (an HTTP error, a network error, or any other answer that isn't the page) it keeps the last state, shows a "paused" note, and waits 6 s, 12 s, 24 s, then 30 s between retries. The first success resets it. Unmounting clears the timer and cancels a poll in flight | `usePoll` has one fixed interval and no error hook to back off on, and it throttles background tabs, which this story leaves out. A partial reload of one prop keeps the page, the scroll and each card's state. The prop comes from our database, so the UI never spends Pixelforge's 60 rpm (D-11). |
| D-42 | History measures time against the server's clock: the `generations` prop carries `server_time`, and the browser keeps its offset from its own clock, re-measured only from a `server_time` newer than any seen, so Back or Forward, which remounts the page from its saved props, keeps the last fresh offset. A restore into a freshly loaded document has none yet and measures from the snapshot until its next poll. The server decides `image_expired` (at or after `image_url_expires_at`) and sends no `image_url` once expired, or when the URL is neither `https://` nor root-relative. A 1 s client tick flips a card that expires while it is open | A skewed browser clock can't misstate the elapsed time or keep a dead image up. A page never requests an expired URL, which would log a console error and fail E2E, and a provider URL can never become a `javascript:` link. |
| D-43 | Failure copy lives on the server, in one enum, `GenerationFailureCode`, keyed by the failure code: the local failure reason if there is one, else Pixelforge's `error.code`. The resource sends only the composed `failure_message`, which the page renders as text. An unknown or missing code reads "This generation failed.", and a test requires a case for every `LocalFailureReason` | Like `status_label`, it is decided where the state lives, so PE-013 and PE-014 append a case and its copy in one place. Provider text (`validation_failed`'s `{param}: {message}`) reaches the page only inside that message, and never as HTML. |
| D-44 | Every call through the Pixelforge contract first takes one unit of one account-wide budget: a `ThrottledPixelforgeClient` decorator around whichever driver is bound, over Laravel's `RateLimiter` on the database cache store (pinned in config, not `CACHE_STORE`), 50 a minute by default (`PIXELFORGE_RATE_LIMIT_PER_MINUTE`). It increments first and compares after. A spent budget throws `RateBudgetExhaustedException` and sends nothing. The fake is wrapped too, with 600 a minute in dev and E2E | The contract is the one door to Pixelforge, so PE-014's polls and PE-018's usage calls cannot skip the budget. The database store's increment is a row-locked read-modify-write, so every process shares one count (D-20), and two workers can never both take the last unit; `RateLimiter::attempt` checks before it hits. The window is fixed, so a rolling minute can see up to twice the limit at a boundary; Pixelforge's 429 handling is the backstop. Wrapping the fake exercises the decorator locally, and the high dev budget never slows parallel E2E. |
| D-45 | Submit bookkeeping lives on the generation in nine `submit_*` columns, written by the submit path in one UPDATE of those columns only. It is outside the transition action and never touches status, costs or the ledger. Each run records its start, and nulls its outcome, before the budget or the request, then records its outcome. A run found with no outcome is ambiguous. The latch is a timestamp that is never cleared. The retry note is its own column, shown only while the generation is `pending`; the column is never cleared, so it means nothing once the row has left `pending`, and the reconciler reads the hand-off flag and the attempt times, never the note | One job per generation runs at a time (D-33's nesting), so it is the only writer and needs no lock, and PE-014 reads plain columns. A separate note column keeps the note on screen while a retry is in flight. Because only a `pending` row shows it, the transition that advances the row (a 202, a replay, a webhook) clears it in the same transaction without knowing about it. |
| D-46 | The submit job is bounded by time. `retryUntil` (dispatch + `PIXELFORGE_SUBMIT_WINDOW` + a 10 min grace) overrides the worker's `--tries=3`. The action measures the window from the first attempt, releases the job itself after each wait, and stops it when the window has ended or the next attempt would start after it; `failed()` runs the same stop. The job's 60 s timeout is config, between the HTTP timeout and `retry_after`. This supersedes D-33's interim tries and backoff | Laravel gives `retryUntil` precedence over `tries`, and every `release()` counts as an attempt, so `--tries=3` would otherwise end a waiting job after three waits. Measuring from the first attempt means queue latency never shortens the window. The grace only decides which path stops the job, and both stop it the same way. Production's systemd unit needs no change. |
| D-47 | Retries treat `Retry-After` as a floor. A 503 backs off by doubling from the first 503, capped at 30 s, and never past the overload deadline. An ambiguous outcome waits clamp(time since the latch, 5 s, 60 s). A 401 is tried 3 times, 20 s apart. A rate limit without `Retry-After` waits 60 s. Any 429 that is not one of the three documented 429 codes is a rate limit (this narrows D-31), and a 404 at create stays ambiguous. The overload deadline and the concurrency max wait run from the first 503 or concurrency 429 of the whole window, not of the current streak. Once any attempt was ambiguous, nothing here fails the generation: it keeps retrying, and at the window's end it is handed to PE-014. Otherwise the window's end fails it free by its last outcome | Doubling from a stored instant needs no counter column, and it holds an overloaded generation to about 9 creates in 2 min instead of 60. A 429 never creates anything, whatever its body says. A latched generation may already exist at Pixelforge and be billed, so failing it would lose the charge (D-09). |
| D-48 | Dev and E2E bounds come from the container environment, not `.env`. `docker-compose.yml` gives app, worker, scheduler and playwright `PIXELFORGE_RATE_LIMIT_PER_MINUTE` (600) and `PIXELFORGE_OVERLOADED_DEADLINE` (15) through one anchor, each defaulted with `${NAME:-…}`, and `.env.example` sets the same values for the CI image. Laravel's immutable dotenv keeps the container's value. Playwright derives its timeouts from the same variable, falling back to `.env.example` in CI. `phpunit.xml` clears both, in `$_ENV` and `$_SERVER`, so the suite always runs with production's bounds | An existing dev `.env` lacks the new names and is never edited, yet `make e2e` runs against it. A developer's own `.env` value still wins, because Compose interpolates from it. Production sets neither, so it keeps the defaults. A test pins that the compose defaults equal `.env.example`. |
| D-49 | Give up only on generations Pixelforge never confirmed (no provider id). After 24 h (`PIXELFORGE_GIVE_UP_AFTER`) they are failed `lost` and free through the transition action, and a late provider success records provider cost only (D-09). Generations with a provider id are polled until terminal and never failed locally; 24 h of `not_found` is logged once for the operator | It is a narrow exception to PE-013's "fail only when nothing was created", limited to `pending` rows, which the transition action already lets fail locally. It keeps History truthful and releases the hold. It never stops tracking a generation Pixelforge knows, so PE-016 and PE-018 provider costs stay complete. |
| D-50 | Staleness is judged by `last_provider_update_at`, our clock when the transition action applied a snapshot on any path. A row is polled once that is older than 2 min (`queued`) or 3 min (`processing`). The reconciler's cadence is a stored next check, `reconcile_recheck_at` = now + clamp(age ÷ 4, 1 min, 15 min). It is written before the request, in an UPDATE of the `reconcile_*` columns alone | The reconciler's own writes must never read as news from Pixelforge. A stored instant keeps the selection one indexed comparison and the formula in one PHP place. Writing it first means a poll that throws still waits its turn, so one bad row cannot drain the budget. Age ÷ 4 reaches 15 min at 1 h, so a 2 h row is polled 4 times an hour. |
| D-51 | A run handles at most 20 rows (`PIXELFORGE_RECONCILE_BATCH`). Polls go first, oldest last update first, and unsubmitted rows, oldest created first, take the rest. A poll is sent only while more than `PIXELFORGE_SUBMIT_RESERVE` (10) units of D-44's budget remain, read from the same key by `PixelforgeRequestBudget`. A spent budget or a 429 stops the run's polls; any other failure is logged and waits for the next check; an exception on one row is logged with its ULID and the run goes on | New submits keep priority: 12 left gives 2 polls. One class owns the key, the store and the limit, so the reserve can never read a different count. Polls are never retried inline, because the cadence is the retry. |
| D-52 | Re-dispatch reuses `SubmitGenerationJob` with the ULID and carries its re-dispatch instant. The job is owed one run that the window does not stop, while no attempt has started since that instant. After it, PE-013's decision is unchanged: a latched row is handed back, any other is failed free by its last outcome. A row is re-dispatched when handed over, or when its last attempt (else its creation) is older than 5 min (`PIXELFORGE_REDISPATCH_AFTER`), at D-50's cadence | The same key and bytes make Pixelforge replay a create that landed, with no second hold or charge, or create it now. Only the start-of-run window check is skipped, and only once, so the latch rule holds. An instant, not a flag, because a released job's payload never changes, and it lets duplicate re-dispatches collapse into one attempt. |
| D-53 | One create in flight per generation: `SubmitGenerationAction` holds `pixelforge-create:{ulid}` on the pinned database store for its whole run, for 90 s (the job's 60 s timeout + 30 s). A held lock releases the job for 5 s and records nothing. `WithoutOverlapping` and `ShouldBeUnique` are not used | The lock outlives a run killed at its timeout, so nothing can overlap it. `WithoutOverlapping` locks on the container's default cache store, which tests run as `array` and which the middleware cannot pin. `ShouldBeUnique` keeps its lock across `release()`, so a re-dispatch made while the first job waits would be dropped. It dedupes dispatches, not runs. |
| D-54 | `app:reconcile-generations` runs every minute with `withoutOverlapping(15 min)` and `onOneServer()` on the database cache (D-20). `PIXELFORGE_RECONCILE_EVERY` may name one of Laravel's sub-minute frequencies (5 s in dev and E2E); production sets nothing | Production's Hestia cron already runs `schedule:run` every minute on one server, so the droplet needs no change. 15 min is past the worst run (20 polls × 35 s). Sub-minute events repeat inside one `schedule:run` process, which memoizes its `onOneServer` mutex, so they still run on one server. |
| D-55 | The fake drops a generation's webhooks when its prompt holds `[no-webhooks]` (case-insensitive, anywhere), or, for manual testing, while `PIXELFORGE_FAKE_DROP_WEBHOOKS` is on. It is decided at create and stored as `drops_webhooks`; the lifecycle still advances and retrieve answers from it. E2E uses only the keyword | Playwright runs fully parallel on shared containers, so only a per-generation switch leaves the other specs their webhooks. Deciding at create matches D-35's chaos rolls. |
| D-56 | History says "Taking longer than usual — we're checking with Pixelforge" on any non-terminal generation 15 min (`PIXELFORGE_STUCK_AFTER`) after creation, by the server's clock, ahead of PE-013's retry note. The reconciler logs one warning per such row, marked by `reconcile_stuck_warned_at` | The notice needs no column, so it appears on time even if the reconciler is down. The marker keeps the log to one line per row. |

## 6. Open questions / known unknowns

- What `error.code` `[fail]` produces (`generation_failed` assumed) — handle both.
- Whether `/v1/usage.generations` counts only succeeded generations — compare costs and tokens, not counts, when reconciling.
- If rates change between estimate and creation, the actual can exceed the hold — settle the actual anyway (we owe Pixelforge), log it.
- An unreachable reference URL is not documented as an error (Pixelforge doesn't download) — validate `http(s)` URL shape only.
