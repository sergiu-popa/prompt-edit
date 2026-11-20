# Story: PE-013-provider-limits-retries-and-throttle

## Title
Provider limits, overloads and outages: bounded retries, free failures and a shared rate budget

## Status
`qa-ready`

## Description
Pixelforge allows each account 60 requests per minute, 3 generations queued or processing at once, and 500 generations per UTC day. It sheds load with `503 overloaded`, and the network between us can fail. PE-009 handles the definitive rejections (400, 409, 422). This story covers everything else in the requirements §4 matrix on the submit path. Each generation ends one of two ways: Pixelforge creates it exactly once, or it fails, costs nothing and shows a clear reason. It is never stuck without explanation and never billed twice.

PE-009's invariant still holds: we fail a generation locally only when Pixelforge has definitely created nothing. A create that timed out may have been accepted, and it may still succeed and be billed to us. If we failed it, we would lose the user charge (D-09 would record only the provider cost) and show the wrong status. Ambiguous outcomes therefore retry with the same idempotency key. If they stay ambiguous, the reconciler (PE-014) takes them over.

Serves grading #2 robustness (primary), #1 correct money (failures are free, a second create never happens) and #3 UX (the user can see what is happening while we wait).

## Acceptance Criteria

**Shared rate budget**
1. Every outbound Pixelforge call made through the contract first takes one unit from a single account-wide budget of `PIXELFORGE_RATE_LIMIT_PER_MINUTE` (default 50, below Pixelforge's 60). All workers and processes share this budget. Today the only such call is the create. PE-014's polls and PE-018's usage calls will draw from the same budget. A test proves that two separate job runs share one count.
2. With the default budget, the 51st call in one 60-second window is not sent. The submit job is released until the budget frees up (at most 60 s later), and the generation stays Queued. Waiting for the local budget never fails a generation on its own. Only the submit window (AC 17) limits it.

**429 `rate_limit_exceeded`**
3. Given `429 rate_limit_exceeded` with `Retry-After: 12`, the next attempt happens no earlier than 12 s later. The generation stays Queued and shows a persisted "retrying" note that survives a reload. Nothing is failed, charged or released.
4. If a 429 has no `Retry-After` or an unparsable one, the job waits a bounded default of at most 60 s. A 429 whose `error.code` is not one of the three documented 429 codes is handled as `rate_limit_exceeded`.

**429 `concurrency_limit_exceeded`**
5. Given `429 concurrency_limit_exceeded` with `Retry-After: 5`, the next attempt happens no earlier than 5 s later. The generation carries a persisted "Waiting for a free slot" marker, and History shows it after a reload.
6. The marker is cleared as soon as the generation advances (a 202, a replay or a webhook) or reaches a terminal state.
7. A generation that is still meeting concurrency 429s after `PIXELFORGE_CONCURRENCY_MAX_WAIT` (default 10 min after its first concurrency 429) is failed free with reason `provider_busy`, unless AC 13 applies.

**429 `daily_limit_exceeded`**
8. Given `429 daily_limit_exceeded` (it carries no `Retry-After`), the generation is failed at once with reason `daily_limit`, unless AC 13 applies. No further Pixelforge call is made for it, and its hold is released. The copy reads "Daily capacity reached, try after 00:00 UTC. You were not charged."

**503 `overloaded`**
9. Given `503` with `error.code` `overloaded` and `Retry-After: 2`, the next attempt happens no earlier than 2 s later. Exponential backoff above that is allowed. The retry sends the same idempotency key and a byte-identical body.
10. Given `503 overloaded` followed by `202`:
    - two create requests are recorded, both with the same `Idempotency-Key` and body;
    - exactly one `provider_id` is stored;
    - the 202 snapshot is applied once through the PE-006 transition action.

    The 202 can be a replay (`Idempotent-Replayed: true`) whose status has already moved on, for example to `succeeded`. It is still applied exactly once. If a webhook advanced the row earlier, that state is kept: `processing` and `succeeded` never go back to `queued`.
11. A generation that is still getting `503 overloaded` after `PIXELFORGE_OVERLOADED_DEADLINE` (default 2 min after its first 503) is failed free with reason `provider_overloaded`, unless AC 13 applies.

**Ambiguous outcomes**
12. These outcomes are ambiguous:
    - a client timeout or a connection error;
    - any 5xx other than a 503 with code `overloaded` (500, 502, 504, or a 503 without that code);
    - a 2xx whose body cannot be parsed into a generation;
    - a job that dies after the request may already have been sent, for example when the worker times out mid-request. The next run detects it as an attempt recorded with no outcome (AC 23).

    Each ambiguous outcome is retried with capped backoff (for example 5 s, 15 s, 30 s, then 60 s), using the same key and a byte-identical body. The generation is never failed locally because of it.
13. The first ambiguous attempt sets a persisted ambiguity latch on the generation. After that, nothing in this story fails it locally. That includes the daily limit, the concurrency and overload deadlines, a 401 and the end of the submit window. The job keeps retrying within its window. When it stops while the latch is set, the generation stays non-terminal with its hold and is handed to the reconciler (AC 23). History keeps showing the "retrying" note.

**401 `invalid_api_key`**
14. `401 invalid_api_key` is retried briefly, at most 3 attempts within 60 s, which covers a key rotated mid-deploy. If it persists, the generation is failed free with reason `service_unavailable`, unless AC 13 applies. One critical-level log entry is written per generation. It contains the generation id and the error code. A test asserts that it never contains the API key, any request header or the `Authorization` value.

**Money**
15. Every local failure in this story goes through the PE-006 transition action.
    - The hold is released exactly once.
    - No charge entry is written.
    - The generation stores provider cost 0 and user charge 0 (PE-007 AC 11).
    - A second failure path for the same generation writes no second release; the PE-007 unique constraint enforces it. An example is a deadline racing the job's final failure.

    Worked example: prompt `A lighthouse at dusk [overloaded]`, which is 33 code points, at medium quality, 1024x1024 and with no references.
    - Text tokens: e = ceil(33 / 4) = 9, so the text bounds are floor(9 × 0.85) = 7 to ceil(9 × 1.15) = 11.
    - Maximum provider cost: 11 × 5,000 + 1,100 × 40,000 + 2,000 × 12,500 = 55,000 + 44,000,000 + 25,000,000 = 69,055,000 n$ ($0.069055000).
    - Hold: ceil(69,055,000 × 125 / 100) = 86,318,750 n$ ($0.086318750, shown as $0.0863).
    - After the overloaded deadline, exactly one release of +86,318,750 n$ is written and no charge entry. The generation's ledger entries net to 0.
16. Retries never produce a second charge. Worked example: prompt `A lighthouse at dusk`, 20 code points, at medium quality, 1024x1024 and with no references.
    - Text tokens: e = 5, so the text bounds are 4 to 6.
    - Maximum provider cost: 6 × 5,000 + 44,000,000 + 25,000,000 = 69,030,000 n$.
    - Hold: ceil(69,030,000 × 125 / 100) = 86,287,500 n$ ($0.086287500).

    The sequence is `503` → `202`, then `generation.succeeded` delivered twice with usage 5 / 0 / 1,100 / 1,500.
    - Provider cost: 25,000 + 44,000,000 + 18,750,000 = 62,775,000 n$ ($0.062775000).
    - Charge: ceil(62,775,000 × 125 / 100) = 78,468,750 n$ ($0.078468750).

    The generation stores provider cost 62,775,000 n$ and user charge 78,468,750 n$. Its ledger holds exactly three entries:
    - one hold of −86,287,500 n$;
    - one release of +86,287,500 n$;
    - one charge of −78,468,750 n$.

    The net is −78,468,750 n$.

**Job bounds**
17. The submit job's retries are bounded by time, not by attempt count. The limit is `PIXELFORGE_SUBMIT_WINDOW` (default 30 min after the first attempt), and the worker's `--tries=3` cannot end the job early. When the window ends and no attempt was ambiguous, the generation is failed free. The reason matches its last outcome:
    - `provider_busy` after rate-limit, local-budget or concurrency waits;
    - `provider_overloaded` after 503s;
    - `service_unavailable` after 401s, or when no request was ever sent.

    If any attempt was ambiguous, AC 13 applies instead.
18. Retries are byte-identical: same method, same URL, `Idempotency-Key` equal to the generation ULID, `metadata.generation_id` equal to the same ULID, and an identical JSON body. A test compares every recorded request in a `503` → timeout → `202` sequence.
19. Timeouts are ordered so that one job never runs twice at the same time: HTTP client timeout < job timeout < the queue connection's `retry_after`. The values live in config, and a test asserts the ordering.
20. A generation can advance while its job is waiting, for example through a webhook after an ambiguous attempt that actually landed. If, at the next attempt, the generation is terminal or already has a `provider_id`, the job exits without calling Pixelforge. Otherwise, a replay is applied without moving the generation backwards.

**UX**
21. History shows the copy in the table under Technical Notes, extending the PE-012 mapping. Every generation that failed in this story shows "Not charged" next to its estimate, and its estimate stays visible.
22. Playwright, fake driver: a prompt containing `[overloaded]` first shows "Pixelforge is busy — retrying automatically". It then shows "Pixelforge was overloaded and didn't accept this request. You were not charged." together with "Not charged", without a page reload. This works within Playwright's timeout because `.env.example` sets a short overload deadline (Technical Notes, Config).

**Reconciler hand-off**
23. Each generation persists submit bookkeeping that PE-014's reconciler reads. These writes go on the generation row outside the transition action, and they never change status, costs or the ledger. The bookkeeping is:
    - the start times of its first and its most recent submit attempt. Each attempt is recorded before its request is sent, so a job that dies mid-request leaves an attempt with no outcome (AC 12).
    - the ambiguity latch (AC 13).
    - a hand-off flag, set only when the submit job stops for good while the latch is set. It is never set while the job is still retrying, so the reconciler never re-dispatches a live job.

    A test asserts each field after a `503` → timeout → window-end sequence.

## Scope

### In Scope
- An account-wide proactive budget below 60 rpm (default 50), shared by all processes and taken by every outbound Pixelforge call.
- `429 rate_limit_exceeded`, including a missing `Retry-After` and unknown 429 codes: wait, then retry. Only the window limits it.
- `429 concurrency_limit_exceeded`: wait, a persisted "Waiting for a free slot" marker, and a bounded total wait followed by a free failure.
- `429 daily_limit_exceeded`: a free failure with reason `daily_limit`.
- `503 overloaded`: backoff that honours `Retry-After`, the same key, and a free failure after a bounded deadline.
- Ambiguous outcomes (timeouts, connection errors, other 5xx, unparsable 2xx, a job dying mid-request): retry with the same key, never fail locally, and hand over to PE-014 when still unresolved.
- `401 invalid_api_key`: a brief retry, then a free failure with a critical log that contains no secrets.
- A time-bounded submit job, aligned with the worker's tries and the queue's `retry_after`.
- Submit bookkeeping for PE-014: the first and latest attempt times, the ambiguity latch and the hand-off flag.
- Every local failure goes through the PE-006 transition action.
- New local reasons appended to PE-009's failure-reason enum, and new copy in the PE-012 status and failure mapping, including a persisted "retrying" note.
- PHP unit and feature tests, JS unit and integration tests, and one Playwright spec.

### Out of Scope
- The reconciler that polls and re-dispatches handed-over or stale generations (PE-014).
- Definitive 400 / 409 / 422 handling (PE-009; unchanged here).
- Proactive local concurrency gating, i.e. not submitting while 3 of ours are in flight. The reactive 429 handling is enough.
- Live verification against the real API (PE-015).
- Health signals and alerting vendors (PE-015 adds only the reconciler health signal).
- User-initiated retry of a failed generation.

## Technical Notes
- **Depends on:**
  - PE-009-pixelforge-client-and-submit-job: the contract, the submit job, the typed errors and the failure-reason enum.
  - PE-012-live-status-results-and-failure-copy: the status and failure copy mapping, and History polling.
  - It also relies on PE-006 (the transition action), PE-007 (hold release, the unique ledger constraints, and provider cost and user charge stored on the generation) and PE-010 (the fake honours `[overloaded]`).
- **Decisions:** D-05 (ULID = idempotency key = `metadata.generation_id`), D-06 (one transition action), D-09 (provider cost is recorded for every provider-succeeded generation even when the user is not charged) and D-14 (the hold is the max estimate). See requirements.md §2.3, §2.4 and §4.
- **Money storage (PE-007):** provider cost and user charge are columns on the generation. The ledger holds only hold, release and charge entries, so this story never writes a "provider cost" ledger entry.
- **Pixelforge facts** (https://pixelforge.promptedit.dev/docs#rate-limits, #errors, #idempotency):
  - Limits are per **account**: 60 requests/min, 3 queued or processing, 500 generations per UTC day.
  - Dev and production keys share these limits. That is why the local budget stays below 60, and why local development uses the fake driver.
  - `Retry-After` is in seconds:
    - `rate_limit_exceeded`: variable;
    - `concurrency_limit_exceeded`: 5;
    - `overloaded`: 2;
    - `daily_limit_exceeded`: none.
  - Authenticated responses carry `X-RateLimit-Limit` and `X-RateLimit-Remaining`. No reset header is documented. Tightening the local budget from `X-RateLimit-Remaining` is optional, so nothing may depend on it.
  - Every retry counts toward the per-minute limit. A replay does not count against the concurrency or daily limits.
  - `503 overloaded` means nothing was created, and it is safe to retry with the same key.
  - Branch on `error.code`, never on `message`.
- **Laravel and repo facts** (Laravel 13 queue docs):
  - `release()` increments the attempt count.
  - When both `retryUntil` and `tries` are defined, `retryUntil` takes precedence.
  - The local worker (`.docker/supervisor/conf.d/worker.conf`) runs `queue:work --sleep=3 --tries=3 --max-time=3600`.
  - The local queue connection is `database`, with `retry_after` 90 s (`DB_QUEUE_RETRY_AFTER`). The production worker's flags come from PE-004's systemd unit. Keep both consistent with AC 19.
- **Budget storage:** the budget must live in a store shared across processes (the database cache store, D-20), never in process memory.
- **Config:** every bound is a config value, and its default is the production value stated above. There is no separate E2E environment:
  - `make e2e` runs against the dev containers.
  - The CI E2E image is built from `.env.example` (PE-010).

  So `.env.example` sets short values, for example a 15 s overload deadline, and local dev, `make e2e` and CI all read them. Production sets none of these variables, so it keeps the defaults.
- **Copy to add to the PE-012 mapping** (plain language). The local reasons are spelled exactly as below in PE-009's enum:

| State or reason | Copy |
|---|---|
| queued + retrying (rate limit, 503, ambiguous, 401 retry) | Pixelforge is busy — retrying automatically |
| queued + waiting for a slot | Waiting for a free slot |
| `daily_limit` | Daily capacity reached, try after 00:00 UTC. You were not charged. |
| `provider_overloaded` | Pixelforge was overloaded and didn't accept this request. You were not charged. |
| `provider_busy` | Pixelforge stayed busy for too long, so we stopped waiting. You were not charged. |
| `service_unavailable` | Service unavailable — this generation wasn't sent. You were not charged. |

- **Required test layers:**
  - **PHP unit:**
    - the retry decision for each response kind, given the generation's attempt history: wait N s, fail free with a reason, or hand over to the reconciler;
    - the ambiguous-latch rule (AC 13);
    - `Retry-After` parsing and its defaults;
    - the budget arithmetic.
  - **PHP feature:** the job with an `Http::fake` sequence for each matrix row, covering:
    - 503 → 202 applied once;
    - a replay that is already `succeeded`, applied once;
    - release delays of at least `Retry-After`;
    - a spent budget, so nothing is sent;
    - each deadline → failed free, with the hold released once and no charge entry (the AC 15 numbers);
    - the AC 16 sequence → stored costs 62,775,000 / 78,468,750 and exactly one hold, release and charge;
    - an ambiguous attempt followed by a daily limit or deadline → still non-terminal and handed over;
    - the bookkeeping fields (AC 23);
    - byte-identical retries;
    - a terminal row or a known `provider_id` → no call;
    - the critical log without secrets;
    - the timeout ordering.
  - **JS unit, or PHP unit if PE-012 put the mapping on the server:** the new entries in the status and failure copy mapping.
  - **JS integration:** the History row in the retrying, waiting-for-slot and each failure state, each showing "Not charged" with the estimate still visible.
  - **E2E** (Playwright, fake driver): `[overloaded]` → retrying → failed, not charged (AC 22).
- **PR size:** an estimated ~550 changed lines including the bookkeeping, over the ~400 budget. Split into two stories before planning, one PR each:
  - PE-013a: the budget, rate-limit and overload handling, the ambiguous handling and latch, the submit bookkeeping (AC 23), the time-bounded window and the timeout ordering.
  - PE-013b: the concurrency marker and max wait, the daily limit, the 401 handling, the copy and the E2E spec.

## Mockups / References
```text
History — rows affected by this story (same card format as PE-011/PE-012)

┌───────────────────────────────────────────────────────────────────┐
│ [Queued]   A lighthouse at dusk             Medium · 1024×1024    │
│            Pixelforge is busy — retrying automatically            │
│            Est. $0.0582 – $0.0863                                 │
├───────────────────────────────────────────────────────────────────┤
│ [Queued]   Portrait of a ginger cat         Low · 1024×1024       │
│            Waiting for a free slot                                │
│            Est. $0.0140 – $0.0219                                 │
├───────────────────────────────────────────────────────────────────┤
│ [Failed]   A lighthouse at dusk [overloaded] Medium · 1024×1024   │
│            Pixelforge was overloaded and didn't accept this       │
│            request. You were not charged.                         │
│            Est. $0.0582 – $0.0863                  Not charged    │
├───────────────────────────────────────────────────────────────────┤
│ [Failed]   Neon city skyline at night       High · 1536×1024      │
│            Daily capacity reached, try after 00:00 UTC.           │
│            You were not charged.                                  │
│            Est. $0.3382 – $0.4476                  Not charged    │
└───────────────────────────────────────────────────────────────────┘
Estimates are user min / max in n$ from the PE-002 rules, formatted by money.ts:
  A lighthouse at dusk (20 cp)               58,150,000 / 86,287,500
  Portrait of a ginger cat (24 cp)           14,031,250 / 21,856,250
  A lighthouse at dusk [overloaded] (33 cp)  58,168,750 / 86,318,750
  Neon city skyline at night (26 cp)        338,156,250 / 447,556,250
```
- https://pixelforge.promptedit.dev/docs#rate-limits
- https://pixelforge.promptedit.dev/docs#errors
- https://pixelforge.promptedit.dev/docs#idempotency
- https://pixelforge.promptedit.dev/docs#testing
- requirements.md §2.3, §2.4 and §4
