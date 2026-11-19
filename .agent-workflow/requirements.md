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
| `429 rate_limit_exceeded` | Release job after `Retry-After`; proactive local throttle below 60 rpm | Queued |
| `429 concurrency_limit_exceeded` | Release after `Retry-After`; bounded wait then fail free | "Waiting for a free slot" |
| `429 daily_limit_exceeded` | Fail free | "Daily capacity reached, try after 00:00 UTC" |
| `503 overloaded` / `[overloaded]` / timeouts / 5xx | Retry with backoff, **same idempotency key** (safe) | Queued |
| `401 invalid_api_key` | Retry briefly, then fail free; log critical | "Service unavailable" |
| `generation.failed` / `[fail]` | Failed, free, store `error` | Friendly text per `error.code` |
| Duplicate event (`[duplicate]`) | Unique `event_id` row inserted in the same transaction as the state change → second delivery is a no-op `200` | — |
| Out-of-order event | Forward-only state machine under row lock; terminal never changes; late `processing` ignored | — |
| Charge recorded twice | Impossible: status guard under `lockForUpdate` **and** a DB unique constraint on the ledger entry per generation | — |
| Webhooks never arrive | Scheduled reconciler polls stale non-terminal generations (rate-budgeted) through the **same** transition action | Status catches up |
| Worker died before submit | Reconciler re-dispatches the submit job (idempotent) | Queued |
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
| D-13 | Deploy on Laravel Cloud (web + queue worker + scheduler, Postgres) | Fastest path the brief suggests; needs workers/scheduler, which Cloud has. |
| D-14 | Upfront hold = **max** estimate; settle actual on success, release on failure | Users can never overspend; actual ≤ max by construction. |
| D-15 | Estimates read a pinned `/v1/pricing` snapshot (`config/pricing.php`, checked 2026-11-18), never fetched at runtime. Live `text_token_variance: 0.15` is held as the integer `text_token_variance_percent: 15` | Keeps PHP, TS and the shared fixture deterministic. Only the estimate depends on it: settlement uses each generation's own `rates`, so a stale snapshot cannot mis-charge. PE-015's live smoke detects drift. A deliberate deviation from the docs' advice to read `/v1/pricing`. |
| D-16 | An estimate's typical count per token type is the midpoint of its min and max, rounded **up** to a whole token; typical is priced like the bounds, with the markup applied once to each total | One integer rule both languages share. With today's tables every midpoint is whole, and the text midpoint is always exactly `e`. |

## 6. Open questions / known unknowns

- What `error.code` `[fail]` produces (`generation_failed` assumed) — handle both.
- Whether `/v1/usage.generations` counts only succeeded generations — compare costs and tokens, not counts, when reconciling.
- If rates change between estimate and creation, the actual can exceed the hold — settle the actual anyway (we owe Pixelforge), log it.
- An unreachable reference URL is not documented as an error (Pixelforge doesn't download) — validate `http(s)` URL shape only.
