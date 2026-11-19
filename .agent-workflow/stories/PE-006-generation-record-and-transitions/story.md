# Story: PE-006-generation-record-and-transitions

## Title
Generation record and the single forward-only transition action

## Status
`qa-ready`

## Description
This story creates the Generation domain, the backbone of grading criteria #1 and #2.

- **Local row first.** A row keyed by a ULID is written with its authoritative, server-side estimate **before** anything is sent to Pixelforge (D-05). A webhook that arrives before our create call returns therefore always finds its row.
- **Snapshot DTO.** A typed DTO parses Pixelforge's generation object, whether it comes from the API or from a webhook's `data.object`.
- **One transition action** serves every path that can change a generation's state: create responses, webhooks, the poller and local failures (D-06). Status only moves forward under a row lock, and terminal states never change.

A table-driven test over every current × incoming status pair proves that duplicate and out-of-order updates can never move a terminal generation. PE-007 then attaches money to exactly those transitions.

## Acceptance Criteria

**Creating the local row**

1. Creating a generation for a user writes one row in status `pending`, with no provider fields set. The row holds:
   - a ULID primary key and the user;
   - the prompt **exactly as given**, with no trimming or normalisation;
   - size, quality and the reference URLs **in the given order**;
   - the user estimate, recomputed server-side by the PE-002 action.

   Worked example: a 40-code-point prompt, medium, 1536x1024, 1 reference stores the user estimate **95,237,500 / 109,312,500 / 123,387,500 n$** (min / typical / max; $0.095237500 / $0.109312500 / $0.123387500), from a provider estimate of 76,190,000 / 87,450,000 / 98,710,000 n$.
2. Creation runs in one transaction: if any write fails, no row exists. PE-007 adds the hold inside this transaction.
3. Stored request fields read back byte-identical to what was given. That includes trailing spaces, emoji, decomposed characters (`café` with U+0301), reference URLs with query strings, and reference order. PE-009 can therefore rebuild an identical payload on every retry, and Pixelforge never answers `409 idempotency_key_reused` to our own retries.
4. The ULID is the only external identifier. No auto-increment generation id exists; the user foreign key is the only integer key on the row.
5. The database rejects a second row with the same non-null provider id (unique constraint), while any number of rows may have none.

**Parsing Pixelforge's generation object**

6. A generation object, or a webhook's `data.object`, parses into a typed snapshot with these fields:
   - `id`, `status`, `size`, `quality`;
   - `metadata.generation_id`;
   - `error {code, message}`;
   - `usage` (null until succeeded);
   - `rates`, kept as the strings received;
   - `image_url`, `image_url_expires_at`;
   - `created_at`, `started_at`, `completed_at` as UTC instants.

   The docs' succeeded example parses to usage 12 / 765 / 6,450 / 3,184 and rates `"5.00"` / `"10.00"` / `"40.00"` / `"12.50"`.
7. **Parsing failures:**
   - Unknown extra top-level fields are ignored.
   - Each of the following throws a typed exception, because nothing is guessed:
     - a missing required field;
     - an unknown `status`;
     - a `succeeded` snapshot without `usage`, `rates`, `image_url` or `completed_at`;
     - a `usage` or `rates` object with a missing or unpriced token key, which follows the PE-001 rules so a token type is never silently dropped.
   - A `failed` snapshot with a null `error` parses, and is later shown as a generic failure.

**Forward-only transitions (D-06)**

8. The status rank is pending < queued < processing < succeeded = failed. Pending is local, before submit, and users see it as "Queued"; succeeded and failed are terminal. A snapshot is applied only when its rank is higher than the row's **and** the row is not terminal. Every pair, as the outcome the action reports:

   | Row \ incoming | queued | processing | succeeded | failed | local failure |
   |---|---|---|---|---|---|
   | pending | applied | applied | applied | applied | applied |
   | queued | ignored: duplicate | applied | applied | applied | refused: conflict |
   | processing | ignored: stale | ignored: duplicate | applied | applied | refused: conflict |
   | succeeded | ignored: terminal | ignored: terminal | ignored: duplicate | ignored: conflict | ignored: terminal |
   | failed | ignored: terminal | ignored: terminal | ignored: conflict | ignored: duplicate | ignored: terminal |

9. An applied snapshot records:
   - the new status and the provider id;
   - the provider timestamps;
   - on succeeded: usage, rates, `image_url` and `image_url_expires_at`;
   - on failed: the error code and message.

   An ignored or conflicting snapshot writes **nothing**: every column, including `updated_at`, is unchanged.
10. **Webhook before the create response:** Pixelforge sends no `queued` event, so a webhook can arrive while the row is still pending.
    - pending → succeeded directly is applied with the full result. The create response's later `queued` snapshot is then reported as "ignored: terminal" and changes nothing.
    - pending → processing is applied. A later `queued` from the create response is reported as "ignored: stale".
11. A late `processing` after `succeeded` is ignored, and succeeded ↔ failed never flips in either direction. Re-applying the same snapshot any number of times is a no-op after the first.
12. A snapshot is **never** applied, and is reported as a conflict, when:
    - its `metadata.generation_id` is missing or is not this row's ULID; or
    - its `id` differs from a provider id already stored on the row.

    Every conflict is logged at error level with the generation ULID, both provider ids and both statuses.
13. **Local failure** (no provider snapshot, e.g. `400 content_policy_violation` at submit):
    - It moves a pending row to failed with a local reason code and an optional provider error code, message and param.
    - It is refused, logged and reported as a conflict on a row Pixelforge has accepted (queued or processing, provider id set). Only Pixelforge's own terminal status may end a generation that Pixelforge may bill.
    - A terminal row ignores it.
14. **Concurrency:** two simultaneous applications of the same `succeeded` snapshot to one row, on two database connections, produce exactly one `applied` and one `ignored: duplicate` outcome, and the row is written once.
15. Pixelforge timestamps are stored in UTC without loss. A `completed_at` of `2026-11-18T23:59:59Z` reads back as that exact instant, and its UTC date is 2026-11-18 even when the PHP and database session timezones are set to something else (e.g. `Europe/Bucharest`). The operator report buckets by this UTC date (D-10).

**Model and factory**

16. The Generation model has no business logic. A factory provides one state per status, and each state satisfies the invariants above:
    - pending has no provider id;
    - succeeded has usage, rates, an image URL with its expiry, and `completed_at`;
    - failed has an error or a local reason.

## Scope

### In Scope
- The `generations` table, storing:
  - the ULID key and owner;
  - the request exactly as given (prompt as unbounded text, size, quality, ordered reference URLs);
  - status and a unique nullable provider id;
  - the user estimate min / typical / max as integer n$ (D-03);
  - the rates as received strings and the four usage counts;
  - the provider error or the local failure reason;
  - image URL and expiry;
  - the provider created / started / completed timestamps.
- A generation status enum with its forward-only rank, terminal check and user-facing label (pending and queued both read "Queued"). It reuses the size and quality enums from PE-002.
- The Generation model (casts, no business logic) and its factory with per-status states.
- The generation snapshot DTO in `app/Support/Pixelforge`, parsed from the generation object or a webhook's `data.object`.
- The create-generation action: input → pending row with the server-side PE-002 estimate, in one transaction.
- The transition action (D-06): locked transaction, forward-only guard, the reported outcome with its reason, and the local-failure path.
- A decisions-log row in requirements §5 (next free D-number): an enum rank plus a guarded update under lock, instead of `spatie/laravel-model-states`.
- Table-driven, replay, conflict, timezone and concurrency tests.

### Out of Scope
- Every money write: hold on create, settle on succeeded, release on failed, and D-09 provider-only cost on a conflict (PE-007).
- The webhook endpoint, signature verification, event-id dedupe and `ping` handling (PE-008).
- The Pixelforge client, request payload builder, `Idempotency-Key` and `metadata.generation_id` on the wire, and the submit job (PE-009).
- The HTTP submit endpoint, its validation (including TrimStrings), the History UI and its index (PE-011, PE-012).
- Staleness bookkeeping, the stale-row query, its index and the poller (PE-014).

## Technical Notes
- **Decisions and sources:**
  - D-05 is shared: the ULID row lives here, and the key and metadata on the wire are PE-009's.
  - D-06, and D-10 for `completed_at`.
  - requirements §2.1: generation object; `rates` fixed at creation.
  - §2.3: lifecycle; replays return the generation "as it is now".
  - §2.5: no `queued` event; at least once, in any order; a retried `processing` can land after `succeeded`; a webhook can land before the POST returns.
  - §4: robustness matrix; the ULID is never an auto-increment id; a deterministic payload avoids 409.
- **State machine choice:** the global guidelines suggest `spatie/laravel-model-states`, but that is a new dependency needing approval. An enum rank plus a guarded update under lock is enough; record the choice in the decisions log.
- **Exactly-once on Postgres:**
  - Lock the row inside the transaction.
  - As defence in depth, make the status write conditional on the row still being in a lower-ranked, non-terminal status, so even a missed lock cannot double-apply.
  - Proving AC14 needs a second real connection. `RefreshDatabase` wraps each test in a transaction, so this one test needs truncation or a dedicated connection; that is the Architect's call.
- **Why the failed + succeeded conflict matters (D-09):** a `409 idempotency_key_reused` means a generation already exists at Pixelforge under our ULID key. PE-009 fails the row locally, but that original generation can still succeed and bill us, and its webhook then hits a failed row. This story only reports and logs that conflict. PE-007 records provider cost only, so the report still matches `/v1/usage`.
- **Why local failure is refused once Pixelforge has accepted a row:** it backs up the PE-009 invariant that a generation is failed locally only when Pixelforge definitively created nothing, so a billed generation can never be lost.
- **Rates stay strings here.** Conversion to n$ (PE-001) happens at settlement in PE-007.
- **Timestamps:** Pixelforge sends whole-second ISO-8601 UTC strings (e.g. `2026-11-04T14:03:29Z`). The app timezone is UTC (`config/app.php`), but AC15 must hold regardless.
- **Storage details:** reference URLs must keep their order. The prompt has no length cap here; the 4,000-code-point limit is validated in PE-011. Laravel's TrimStrings middleware is PE-011's concern, because this action stores exactly what it is given.
- **Consumers** (keep the surface to what these stories need):
  - The create action: PE-007, which adds the hold in its transaction, then PE-011 over HTTP.
  - The transition action: PE-007 (settle/release on applied terminal outcomes, D-09 on conflicts), PE-008 (webhooks), PE-009 (create and replay responses), PE-014 (poller).
  - Local failure: PE-009 and PE-013.
- **Required test layers:**
  - PHP unit (`tests/Unit`, database-backed where an action writes):
    - snapshot parsing from the docs' generation JSON and from a webhook envelope's `data.object`, including every AC7 rejection;
    - status rank, terminal check and labels;
    - the create action with the AC1 worked example and the AC3 round-trip;
    - the table-driven transition test over every AC8 pair, asserting the outcome and that ignored or conflicting cases leave the row untouched;
    - the AC10 webhook-first sequences, the AC12 conflicts and the AC13 local-failure rules;
    - the AC15 timezone test;
    - the AC14 concurrency test.
  - No PHP feature, JS or E2E tests: there is no endpoint or UI yet.
- **Depends on:** PE-002-upfront-estimate-shared-fixture (estimate action, size and quality enums). The users table already exists.
- **PR size:** estimated 600–700 changed lines: a migration, enum, model, factory, DTO, two actions and five test groups. That is over the ~400 budget, so split it before planning into two stories:
  - (a) migration, status enum, model, factory, snapshot DTO and the create action (AC1–AC7, AC15–AC16);
  - (b) the transition action with its table-driven, replay, conflict and concurrency tests (AC8–AC14).

## Mockups / References
No UI.

- Generation object and lifecycle (whole-second UTC timestamps, `rates` fixed at creation): https://pixelforge.promptedit.dev/docs#generation-object
- Webhooks (no `queued` event, at least once, any order, early delivery, never move back from succeeded/failed): https://pixelforge.promptedit.dev/docs#webhooks
- Idempotency (same key + same params → the generation as it is now; different params → 409; keys never expire): https://pixelforge.promptedit.dev/docs#idempotency
- Retrieve a generation (the poller's source in PE-014): https://pixelforge.promptedit.dev/docs#retrieve-generation
- requirements.md §2.1, §2.3, §2.5, §4; decisions D-05, D-06, D-09, D-10
