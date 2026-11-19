# Story: PE-008-verified-idempotent-webhooks

## Title
Signature-verified, idempotent, order-independent Pixelforge webhooks

## Status
`draft`

## Description
Pixelforge reports each generation's progress and outcome through webhooks. Every event is delivered at least once and in any order, sometimes before our `POST /v1/generations` call has returned (requirements §2.5), and anyone on the internet can POST to the URL. Verified, idempotent, order-independent webhooks are a required feature. They protect grading criterion #1 (a charge is never recorded twice, and failed stays free) and criterion #2 (robustness).

This story adds the endpoint. It verifies the HMAC signature over the raw body and records each event id in the same transaction as the state change (D-07). It then hands the snapshot to the single transition action (D-06), so PE-007's ledger settles or releases exactly once.

Inbound exactly-once is proven against the ledger before any outbound call exists: tests drive locally created generations directly.

## Acceptance Criteria
“Signed” means a `Pixelforge-Signature: t=<unix>,v1=<hex>` header, where the hex value is the HMAC-SHA256 of `<t>.<raw body>` keyed with `PIXELFORGE_WEBHOOK_SECRET`. Tests freeze time and prevent stray HTTP.

**Lighthouse** is a local generation for the docs request (`high`, `1536x1024`, 1 ref) that holds −457,150,000 n$. Its succeeded snapshot carries usage 12 / 765 / 6,450 / 3,184 at rates `5.00` / `10.00` / `40.00` / `12.50`.

**Transport and verification**
1. `POST /webhooks/pixelforge` answers a correctly signed request with 2xx, even with no session cookie, no CSRF token and no signed-in user.
   - The response sets no cookie.
   - The response is never a 3xx, because Pixelforge does not follow redirects.
   - No app rate limiter applies: 100 signed `ping`s sent back to back all get 2xx.
2. The signature is checked against the exact raw request bytes. The same event re-encoded (keys reordered or whitespace changed) and sent with the original signature is rejected with 400.
3. Each of these requests is rejected with 400, persists nothing and touches no generation or ledger entry:
   - there is no `Pixelforge-Signature` header
   - the header is malformed: no `t`, no `v1`, or a non-numeric `t`
   - the signature was made with a different secret
   - the body was changed by one byte
   - the signature is valid, but for a different `t` than the header states
4. Replay window, with time frozen: `t = now − 300` and `t = now + 300` are accepted, while `t = now − 301` and `t = now + 301` are rejected with 400.
5. Every rejection is logged with its reason (`missing`, `malformed`, `mismatch` or `stale`) and with the `Pixelforge-Event-Id` header if present. With a sentinel secret configured, no log line contains the secret or the request body.
6. If no webhook secret is configured, every request is rejected with 500 and logged at critical level, even one signed with an empty key. The HMAC is never computed with an empty key.
7. Some bodies are validly signed but malformed: not valid JSON, missing `id` or `type`, or a `generation.*` event without `data.object`. Each is rejected with 400, persists nothing and is logged at error level. A `ping` is accepted with or without `data.object`.

**Applying events (each answered with 2xx unless stated)**
8. A signed `generation.succeeded` for Lighthouse:
   - sets the status to `succeeded`
   - records provider cost **305,510,000 n$ ($0.305510000)** and user charge **381,887,500 n$ ($0.381887500)**, leaving a ledger net of **−381,887,500 n$**
   - stores exactly one event record holding the event id, type, delivery attempt, generation and time received
9. The same event sent again is a no-op, whether it comes later or as a dashboard resend with a higher `Pixelforge-Delivery-Attempt` and a fresh `t`. There is still one event record, one `release` and one `charge`.
10. Two deliveries of the same event racing on separate DB connections both get 2xx, never 5xx. The result is one event record and one charge.
11. Two different event ids carrying the same succeeded snapshot produce two event records and still exactly one charge.
12. Out-of-order events are recorded with no change to status, cost or ledger:
    - a `generation.processing` after `generation.succeeded`
    - a `generation.failed` after `generation.succeeded`
    - a `generation.succeeded` after a provider `generation.failed`
13. Webhook before the create response: a succeeded event can arrive for a local generation that has no provider id yet. It is matched by `data.object.metadata.generation_id` (our ULID). The generation succeeds, stores provider id `gen_…` and is charged once (381,887,500 n$).
14. Fallback matching: an event without a usable `metadata.generation_id` is matched by `data.object.id` against the stored provider id.
15. Conflicting identity: the generation found by `metadata.generation_id` may already store a provider id that differs from `data.object.id`. The event is then recorded but not applied (no change to status, cost or ledger), and an anomaly is logged with both ids.
16. A `generation.failed` with `error.code` `generation_failed` or `internal_error`:
    - marks the generation failed
    - stores the error code and message
    - records provider 0 and user 0
    - releases the hold, for a net of 0
17. A `generation.processing` on a queued generation moves it to `processing`, stores `started_at` and writes no ledger entry.
18. The snapshot's `status` decides the transition, not the event type (the docs say “go by the generation's status”). A `generation.succeeded` event whose snapshot is still `processing` only moves the generation to `processing`, and the mismatch is logged.
19. D-09 through the endpoint: a succeeded event for a locally failed Lighthouse keeps it `failed`, records provider cost 305,510,000 n$ and charges nothing (PE-007 AC 20).
20. A `ping` is recorded and touches no generation.
21. An unknown event type is recorded, logged as a warning and changes nothing.
22. An event for an unknown generation has no ULID or provider-id match. This includes a malformed or 500-character `generation_id`. It is recorded without a generation and logged as a warning. It changes nothing and never causes a DB error.
23. Unexpected failure: applying the snapshot may throw, e.g. because a ledger write fails or PE-007's bad-rate rollback fires.
    - The event record, the status and the ledger all roll back.
    - The endpoint answers 500, so Pixelforge retries.
    - A later redelivery of the same event is processed normally and charged once.
24. Deduplication and routing use the signed body's `id` and `type`. The unsigned `Pixelforge-Event-Id` and `Pixelforge-Event-Type` headers are informational only. When they disagree with the body, the body wins and the mismatch is logged.
25. Handling a webhook makes no outbound HTTP call.

**Docs**
26. The README explains local development against real webhooks. The fake driver (PE-010) stays the default.
    - Expose the app through a tunnel (Expose, ngrok or Cloudflare Tunnel).
    - In the dashboard, set a dev API key's webhook URL to `<tunnel>/webhooks/pixelforge`.
    - Put that key's `whsec_…` secret in `.env`, never committed.
    - Press “Send test event” and check that the `ping` gets 2xx.
27. requirements.md §5 gains decisions-log rows, using the next free D-numbers, for these decisions:
    - answering 2xx to unknown generations and unknown types
    - trusting the signed body over the unsigned headers
    - recording every verified event id, including pings and unknown types

## Scope

### In Scope
- A webhook route outside the web middleware group (no session, cookies or CSRF), outside auth and without a rate limiter. The raw body is read before JSON decoding.
- Pixelforge config holding the webhook secret and the 300 s tolerance. `PIXELFORGE_WEBHOOK_SECRET` is listed in `.env.example` with no value.
- A signature verifier and a matching signer in `app/Support/Pixelforge`. The signer serves these tests and PE-010's fake.
- An event record, unique by event id, written in one transaction that also matches the generation and applies its snapshot through PE-006's transition action.
- The response policy for pings, unknown types, unknown generations, identity conflicts and unexpected errors.
- The README section and the decisions-log rows.

### Out of Scope
- The outbound client and submission: PE-009-pixelforge-client-and-submit-job.
- The fake driver that sends signed webhooks: PE-010-fake-pixelforge-driver.
- Polling for webhooks that never arrive: PE-014-reconciliation-poller.
- The production webhook URL and secret: PE-015-production-go-live.
- Queuing webhook work for later. It isn't needed, because the handler is DB-only and fast (D-07).
- Several `v1` signatures in one header, and secret rotation. Pixelforge does not document either.

## Technical Notes
- **Unparseable snapshot (PE-007 AC18):** a validly signed `generation.*` event whose `data.object` makes `GenerationSnapshot::from()` throw, e.g. a succeeded object without `usage`, is logged at error level with `data.object.metadata.generation_id` and the event id, and its response is decided explicitly (400 = no retry, or 500 = retry).
- **Depends on:** PE-007-credit-ledger-hold-settle-release, and through it PE-006's transition action and PE-001's money.
- **Decisions and sources:** D-05, D-06, D-07 and D-09; requirements §2.5 (webhook facts) and §4 (the duplicate and out-of-order rows).
- **Pixelforge facts:**
  - **Headers:** `Pixelforge-Event-Id`, `Pixelforge-Event-Type`, `Pixelforge-Delivery-Attempt` and `Pixelforge-Signature: t=<unix>,v1=<hex>`. The delivery-attempt count includes dashboard resends. `User-Agent: Pixelforge-Webhooks/1.0` is not authentication.
  - **Signature:**
    - It is the hex HMAC-SHA256 of `<t>.<raw body>`, keyed with the `whsec_…` secret of the API key that created the generation.
    - Compare with `hash_equals`, and reject when |now − t| > 300 s.
    - Each attempt carries a fresh `t`.
    - The docs' Laravel example answers 400 on failure.
  - **Events:** `generation.processing`, `generation.succeeded`, `generation.failed` and `ping`. There is no `queued` event.
  - **Envelope:** `{id: evt_…, object: event, type, created_at, data: {object: <generation snapshot>}}`.
  - **Delivery:**
    - Any 2xx within 10 s counts as delivered.
    - Otherwise Pixelforge retries after 10 s, 30 s, 2 m, 10 m and 15 m (6 attempts over 27 min 40 s), then gives up.
    - Redirects are never followed.
    - Duplicates share the event id.
- **Raw bytes:** verify `getContent()`, never re-encoded JSON, and make sure no middleware rewrites the body.
- **Concurrency:** two concurrent deliveries can both pass an existence check, so rely on the unique index with a conflict-tolerant insert. On Postgres a raw unique violation aborts the whole transaction (see PE-007). With `ON CONFLICT DO NOTHING`, the racing duplicate waits for the first transaction. If that commits, the duplicate is a no-op; if it rolls back, the duplicate is processed normally.
- **Why 2xx for unknown generations:** a 4xx or 5xx only buys 27 min 40 s of pointless retries. Our row always exists before submission (D-05), so an unknown generation is foreign data, e.g. an event from before a DB reset.
- **Safe lookup:** lookups must accept arbitrary strings. A non-ULID `generation_id` must not raise a DB error, because that would abort the transaction.
- **Speed:** the 10 s budget covers DB work only. Nothing slow or outbound runs in the request.
- **Secrets:** each API key has its own URL and secret, so local and production differ (PE-015). Never log or commit `PIXELFORGE_WEBHOOK_SECRET`.
- **Required test layers:**
  - PHP unit, the verifier and signer with time frozen:
    - header parsing and missing parts
    - the 300 s / 301 s boundaries in both directions
    - a tampered body, a wrong secret and an empty secret
  - PHP feature: the endpoint, asserting generation and ledger state for AC 1–25, with stray HTTP prevented. The racing duplicates (AC 10) need separate DB connections, outside `RefreshDatabase`'s wrapping transaction.
  - JS unit, JS integration, E2E: none, because there is no UI. E2E exercises this path end to end once the fake exists (PE-011, PE-012).
- **PR size:** about 600 changed lines with tests, which is over the ~400 budget. Split it in two:
  - PR 1: the verifier, signer and config, with their unit tests.
  - PR 2: the endpoint, event records, handling, README and decisions-log rows, with all the feature ACs.

## Mockups / References
No UI.
- https://pixelforge.promptedit.dev/docs#webhooks: events, envelope, headers, signature verification and its Laravel example, retries, at-least-once and any-order delivery, and tunnels for local development.
- https://pixelforge.promptedit.dev/docs#generation-object: the snapshot carried in `data.object`.
- https://pixelforge.promptedit.dev/docs#testing: `[duplicate]`.
- requirements.md §2.5, §4 and §5 (D-05, D-06, D-07, D-09).
