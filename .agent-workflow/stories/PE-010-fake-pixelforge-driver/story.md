# Story: PE-010-fake-pixelforge-driver

## Title
In-app fake Pixelforge with signed webhooks and test keywords

## Status
`done`

## Description
Local development and Playwright E2E need a Pixelforge that is deterministic, free and reachable without a public URL (D-12). Real Pixelforge only calls public webhook URLs, and CI has no API key.

This story adds an in-app fake that implements the PE-009 client contract and honours the documented test keywords and idempotency semantics. It moves each generation through its lifecycle with delayed queued jobs, then POSTs correctly signed webhooks back to our real endpoint. Every local run and every E2E flow therefore exercises signature verification, deduplication and the ledger end to end.

The fake underpins grading criterion #2 (robustness that can be demonstrated) and the user's requirement for real E2E tests. It is test infrastructure, not product, so it stays small and can never run in production.

## Acceptance Criteria
**Lighthouse** is the prompt `A lighthouse on a cliff at dawn, oil painting` (45 code points, so e = 12 and the text bounds are 10–14), `high`, `1536x1024`, with 1 ref. Its estimate is 347,750,000–457,150,000 n$.

**Binding and safety**
1. `PIXELFORGE_DRIVER=fake` binds the fake as the Pixelforge client.
2. With `APP_ENV=production` and the fake driver configured, the app refuses to boot with an error naming the setting.
3. The fake's placeholder-image route answers 404 when the fake driver is not active.
4. With no `PIXELFORGE_WEBHOOK_SECRET`, a create through the fake raises a clear configuration error. The fake never sends a webhook signed with an empty key.

**Create and idempotency**
5. A create returns a snapshot shaped like the real one:
   - a `gen_…` id, `object` `generation` and status `queued`
   - prompt, size, quality and references echoed back
   - `metadata` echoed as an object
   - rates `5.00` / `10.00` / `40.00` / `12.50`
   - `usage`, `image_url`, `image_url_expires_at`, `error`, `started_at` and `completed_at` all null
   - `created_at` as an ISO-8601 UTC timestamp
6. The same idempotency key with the same params returns the same generation as it is now, with `replayed` = true. It starts no new lifecycle and sends no new webhooks.
7. The same key with different params raises the typed `idempotency_key_reused` error.
8. Fake state is persistent and shared by the web, worker and E2E processes. A replay handled by a fresh app instance sees the same generation.

**Keywords (case-insensitive, anywhere in the prompt)**
9. `[blocked]` (also `[BLOCKED]` and `[Blocked]`) raises `content_policy_violation`. Nothing is created and no webhook is sent. A retry with the same key is rejected the same way.
10. `[overloaded]` raises `overloaded` with Retry-After 2. Nothing is created, and a retry with the same key is rejected the same way.
11. `[fail]` is accepted and sends `generation.processing`. It then sends `generation.failed` with `error.code` `generation_failed`, a message and `completed_at` set; `usage` stays null.
12. `[succeed]` never fails at random, even with chaos forced on.
13. `[slow]` keeps the generation in `processing` for the configured slow duration, which is longer than normal processing.
14. `[duplicate]` delivers every webhook twice. Both copies carry the same event id and body, and each is signed with its own fresh `t`.
15. Combined keywords follow a documented and tested precedence. `[blocked]` comes before `[overloaded]`, which comes before `[fail]`. `[slow]`, `[duplicate]` and `[expire]` combine with any accepted outcome.
16. Random chaos is off by default. With default settings, 200 creates never fail at random, return 503 or duplicate a webhook. When chaos is configured (as a rate) or forced in tests, each documented chaos outcome can be produced:
    - a 503 `overloaded` on create
    - an `internal_error` failure
    - a duplicated webhook

**Lifecycle, usage and images**
17. After the configured queue delay, a `generation.processing` webhook is sent with `started_at`. After the processing delay, the terminal webhook follows with `completed_at`. Both delays are configurable, and with the defaults a normal generation completes within a few seconds.
18. A succeeded Lighthouse reports this usage:
    - image 765 and output 6,450
    - text within [10, 14]
    - reasoning within [1,000, 8,000]
    - `total_tokens` equal to their sum

    Its provider cost therefore lies within [278,200,000; 365,720,000] n$, and its user charge within [347,750,000; 457,150,000] n$ ($0.347750000–$0.457150000). That is inside the PE-002 estimate.

    For any prompt, the same rules apply:
    - text lies within [floor(e × 0.85), ceil(e × 1.15)], where e = ceil(code points / 4) of the full prompt, keywords included
    - image = 765 × refs
    - output comes from the quality × size table
    - reasoning lies within the quality's range
19. Usage is reproducible: the same generation always gets the same usage, so tests can assert exact amounts.
20. A succeeded generation's `image_url` points at the fake's placeholder PNG.
    - It loads in the browser both in dev (`http://prompt-edit.localhost`) and in E2E (`http://127.0.0.1:8080`).
    - `image_url_expires_at` is `completed_at` + 24 h by default, and the TTL is configurable. After expiry the URL answers an error, like a dead signed URL.
    - A fake-only keyword, `[expire]`, gives that one generation a short TTL (configurable, a few seconds). E2E can then show expiry without affecting other tests.

**Webhooks into our real endpoint**
21. Webhooks are POSTed to the configured in-network base URL (default `http://app:8080`) plus `/webhooks/pixelforge`.
    - Headers: `Content-Type: application/json`, `User-Agent: Pixelforge-Webhooks/1.0`, `Pixelforge-Event-Id`, `Pixelforge-Event-Type`, `Pixelforge-Delivery-Attempt`, and a `Pixelforge-Signature` made by PE-008's signer.
    - Body: the documented envelope, with an `evt_…` id, `object` `event`, `type`, `created_at`, and `data.object` set to the full snapshot at that moment.
22. Round trip: captured fake webhooks, replayed byte for byte with their headers into the real endpoint, pass verification and settle the generation. For a `[duplicate]` succeeded Lighthouse:
    - all four deliveries get a 2xx
    - the ledger holds exactly one `release` and one `charge`
    - the charge equals ceil(provider × 125 / 100) of the snapshot's usage and lies within [347,750,000; 457,150,000] n$
23. A failed delivery (a non-2xx or a connection error) is logged with the event id and status, and the fake's own state stays correct. There is no redelivery; PE-014 recovers a lost terminal webhook.

**Environments**
24. `.env.example` and the dev/E2E setup default to the fake driver with the in-network webhook URL.
    - A checkout set up with `make setup` has a working local webhook secret that is never a real dashboard `whsec_…` and is never committed.
    - The existing Docker `worker` service runs the fake's jobs.
    - `make e2e` (the bootstrap's welcome smoke test) still passes.

## Scope

### In Scope
- The fake driver, implementing PE-009's contract (create only) and selected by `PIXELFORGE_DRIVER=fake`.
- The production boot guard and the empty-secret guard.
- Persistent fake state, shared across processes, with idempotency semantics.
- The six documented keywords, plus the fake-only `[expire]`.
- Random chaos, off by default, with a configurable rate.
- The lifecycle, driven by delayed queued jobs with configurable timings.
- Realistic, reproducible usage at the pinned rates.
- Signed webhook delivery to an in-network URL, through PE-008's signer.
- A placeholder PNG route with expiry, active only with the fake.
- Dev and E2E env defaults, the env var names in `.env.example`, and a local webhook secret generated on setup.

### Out of Scope
- `getGeneration` and a “drop webhooks” switch: PE-014-reconciliation-poller.
- The usage endpoint: PE-018-usage-reconciliation-and-final-signoff.
- The live smoke test against the real API: PE-015-production-go-live.
- Simulating the rate, concurrency and daily limits: PE-013-provider-limits-retries-and-throttle, using `Http::fake`.
- Matching the real API's request validation. Our Form Request and payload DTO tests cover it, and PE-015's live smoke test verifies it.
- Webhook redelivery schedules, and deliberately out-of-order delivery. PE-008's feature tests cover ordering.
- Playwright user flows: PE-011-submit-generation-and-history, PE-012-live-status-results-and-failure-copy.

## Technical Notes
- **Depends on:**
  - PE-008-verified-idempotent-webhooks: the endpoint and the signer.
  - PE-009-pixelforge-client-and-submit-job: the contract, the typed errors and the snapshot DTO.
- **Decisions:** D-12; requirements §2.3, §2.5, §2.7 and §3. Section 3 gives the estimate bounds the fake's usage must respect.
- **Location:** `app/Support/Pixelforge/`, with the rest of the provider integration (project CLAUDE.md).
- **Two URLs, two audiences:**
  - Webhooks go from the worker to the in-network app at `http://app:8080` (the compose service is `app`, with Apache on 8080), never to the browser-facing `prompt-edit.localhost`.
  - The browser loads image URLs. Playwright shares the app container's network namespace and browses `http://127.0.0.1:8080`. There, `http://prompt-edit.localhost` points at loopback port 80, where nothing listens, because Traefik runs in another container. An absolute image URL based on `APP_URL` therefore breaks E2E.
  - If PE-006's snapshot DTO requires an absolute `image_url`, the Architect must reconcile that with AC 20.
- **E2E uses the dev containers:** `make e2e` runs Playwright against the dev `app` and `worker`, so E2E gets the dev defaults. Keep default timings to a few seconds, and scale `[slow]` down by config.
- **`[expire]` per generation:** Playwright runs fully parallel across the desktop and mobile projects, so a global short TTL would break the other image assertions.
- **Webhook secret:** PE-008 lists `PIXELFORGE_WEBHOOK_SECRET` in `.env.example` with no value. This story makes setup generate a random local value, or an equivalent the Architect picks. CI E2E needs the same.
- **State store:** it must be shared by the web and worker processes, in the database (there is no Redis, D-20); the Architect picks the table or cache store. A fake-only table would also exist in production, harmlessly, because the fake cannot be bound there.
- **PHP tests:**
  - The `sync` queue runs delayed jobs immediately, so the whole lifecycle and its webhooks happen inside the create call. That makes a natural webhook-before-response case.
  - Capture outgoing webhooks with `Http::fake`, then replay their exact bytes and headers into the endpoint, rather than relying on the network.
- **Risks:** the fake can drift from the real API; PE-015's live smoke test guards against that. Binding the fake in production must be impossible (AC 2).
- **Required test layers:**
  - PHP unit:
    - keyword detection and precedence
    - the usage generator's bounds and reproducibility, over many generated prompts, against the PE-002 bounds
    - chaos gating
  - PHP feature:
    - binding and both guards
    - idempotency across fresh app instances
    - every keyword's lifecycle
    - webhook round trips into the real endpoint with ledger assertions, including `[duplicate]` charged once
    - image-route expiry, and 404 when the fake is inactive
  - E2E: none owned here. The existing smoke test must stay green; the flows arrive in PE-011 and PE-012.
  - JS unit, JS integration: none.
- **PR size:** about 650 changed lines with tests, which is over the ~400 budget. Split:
  - PR 1: AC 1–10, 12, 17–19, 21, 23 and 24, plus AC 20 without expiry and AC 22 without `[duplicate]`.
  - PR 2: AC 11 and 13–16, plus AC 20's expiry and `[expire]`, and AC 22's `[duplicate]` case.

## Mockups / References
No UI. The placeholder PNG is test infrastructure.
- https://pixelforge.promptedit.dev/docs#testing: keyword behaviours and unprompted chaos
- https://pixelforge.promptedit.dev/docs#idempotency
- https://pixelforge.promptedit.dev/docs#webhooks: envelope, headers and signing
- https://pixelforge.promptedit.dev/docs#generation-object: snapshot shape and lifecycle timings
- https://pixelforge.promptedit.dev/docs#images: 24 h expiry
- https://pixelforge.promptedit.dev/docs#pricing: the token rules the fake's usage must respect
- requirements.md §2.3, §2.5, §2.7, §3, §5 (D-12)
