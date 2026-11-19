# Review — PE-008-verified-idempotent-webhooks

## Round 1

**Verdict: APPROVE**

Scope reviewed: the 14 `[PE-008]` commits `5b7e4a0..44dbe9c` on `pe-008-verified-idempotent-webhooks` (`git log origin/main..HEAD`, which also holds the PE-007 commits underneath), against `story.md` and `plan.md`.

Checks run on HEAD (`chain-make.sh chain check`, exit 0):
- Pint and Larastan are clean.
- PHPUnit runs 745 tests with 3,613 assertions, all passing.
- ESLint, Prettier and vue-tsc are clean, and Vitest runs 107 tests, all passing.

### Summary

The implementation matches the plan class for class, and every plan task is checked.

- **Transport (AC1, AC6, AC25):**
  - `routes/webhooks.php` is loaded through `withRouting(then:)` with no group (`bootstrap/app.php:15-17`). Only `VerifyPixelforgeWebhookSignature` is gathered, and a test asserts that.
  - There is no session, cookie, CSRF, auth or throttle, so no `Set-Cookie` header is sent and 100 back-to-back pings all get 200.
  - The controller has no Form Request and no `validate()`, so nothing can answer 302.
  - `Http::preventStrayRequests()` runs in every webhook test, and AC8 also asserts `Http::assertNothingSent()`.
- **Signature (AC2–AC6):**
  - `WebhookSignature` (`app/Support/Pixelforge/WebhookSignature.php`) checks the secret first. A null or `''` secret throws not-configured from both `sign()` and `verify()`, so `hash_hmac` never runs with an empty key.
  - The HMAC covers the header's `t` exactly as sent, `.` and `getContent()`, compared with `hash_equals`. The signature is checked before staleness, so `stale` only ever means a genuine old delivery.
  - Parsing refuses a repeated `t` or `v1`, a part without `=`, an empty `v1`, and a `t` that is not 1–18 digits.
  - The ±300/±301 boundaries are pinned in both the unit test and the endpoint test.
  - The padded-string ping proves TrimStrings never reaches the signed bytes.
- **Body (AC7, AC24):**
  - `WebhookDeliveryData::from()` decodes with `JSON_THROW_ON_ERROR`. It refuses a list, and an `id` or `type` that is not a 1–255 character string or that holds a NUL byte. Malformed UTF-8 fails the decode.
  - Generation types need `data.object` as an object. Pings and unknown types are never read past the envelope.
  - The attempt header is clamped to the `integer` column, so it can never cause a DB error.
  - Dedupe and routing use the body. A header that disagrees is logged with both values.
- **Exactly once (AC8–AC11, AC23):**
  - `HandlePixelforgeWebhookAction` locks the matched generation before `fillAndInsertOrIgnore` writes the event row. The comment explains the `FOR KEY SHARE` deadlock this avoids.
  - Only an inserted row reaches `TransitionGenerationAction`, so PE-007's settle and release run once. The figures 305,510,000, 381,887,500 and −381,887,500 are asserted as exact ints.
  - The rollback test fails the charge insert once, then shows a 500, no event row, an unchanged raw row and `[hold]`. A redelivery afterwards charges once. The `5.0001` rate shows the same rollback and PE-007's pricing log.
  - The two-process race asserts `[200, 200]` with one row and one charge. The rollback branch, where both children wait on an uncommitted event id, ends with exactly one row.
- **Matching (AC13–AC15, AC22):**
  - The ULID is queried only when `Str::isUlid()` passes, so `<ULID>\0x` and the 500-character id never reach Postgres. A dedicated test proves `<our ULID>\0x` never matches our row.
  - The provider-id fallback then runs. D-27 changes `classifySnapshot` minimally: a present foreign `generation_id` is still refused, and a missing one is accepted only against a stored provider id, with a warning.
  - The conflicts table was updated for every status, so PE-006 AC12 is narrowed explicitly rather than silently.
- **Policy (AC12, AC16–AC21):**
  - Out-of-order events are recorded and change nothing. Raw-row comparisons with a one-minute `travel()` prove it.
  - Failures store 0 / 0 with `[hold, release]`, a processing event stores `started_at` and adds no ledger entry, and D-09 keeps the cost and charges nothing.
  - The snapshot's status decides, and a type that disagrees logs one warning. Pings stay silent, while unknown types and unknown generations log a warning.
- **Logs (AC5):**
  - Every rejection logs its reason and the header event id, and only those.
  - The sentinel-secret matrix covers all four rejection reasons, an unreadable JSON body, an unreadable snapshot, an unknown generation and two applied events.
- **Unparseable snapshot (Technical Notes):** it gets a 400 with `event_id` and `metadata.generation_id` at error level, and nothing is recorded. D-28 records the choice. The plan's Risks section correctly notes that a 400 is retried too, because only a 2xx counts as delivered.
- **Docs (AC26, AC27):**
  - The README's "Real webhooks locally" section lists the three tunnels, the dev key's URL, the `.env` secret and "Send test event". It also says how to read each refusal.
  - D-28, D-29 and D-30 follow D-27 with no gap, and `.env.example` lists `PIXELFORGE_WEBHOOK_SECRET=` empty.
- **Style and security:**
  - Every file declares `strict_types`, every class is `final`, every method has a return type, and there is no `else`.
  - Action and controller share the `HandlePixelforgeWebhook` prefix.
  - Support imports nothing from `App\Http`.
  - The event table's FK uses `restrictOnDelete`, as `credit_ledger_entries` does.
  - Exception messages name fields and never echo the body.

### Commit hygiene

- The plan (`5b7e4a0`) and the checkboxes and status (`44dbe9c`) each have their own bookkeeping commit.
- There is one code commit per plan task (`2b14862`..`ba3d54d`), and each one carries its tests. Tasks 6, 9 and 11 pin behaviour with tests only, as planned.
- The D-27 row comes with the transition change it documents (`3d21aac`). D-28 to D-30 come with the README (`ba3d54d`), as the plan specified.
- Every commit ends with the trailer. Every subject but one is at most 67 characters (see S3).
- I ran the checks on HEAD only. Each intermediate commit is self-contained by inspection. Tasks 2–4 answer 200 without applying generation events, but they only ever ship together with task 5 in the one PR.

### Suggestions

None of these blocks QA.

- [ ] **S1. D-30 says a redelivery is "never a second warning", but a header mismatch is logged on every delivery, duplicates included.**
  - `app/Domain/Generation/Actions/HandlePixelforgeWebhookAction.php:40` calls `logHeaderMismatches()` before the transaction, and so before the dedupe insert. `PixelforgeWebhookEventsTest::test_dedupe_uses_the_signed_body_id_not_the_event_id_header` expects two warnings for one event id.
  - Logging every disagreeing delivery is the more useful behaviour, because a replay with rewritten headers is exactly what an operator wants to see.
  - Fix: reword D-30 (`.agent-workflow/requirements.md:242`), for example "…never a second warning about the event itself; only a header that disagrees with the body is logged again".
- [ ] **S2. The rejection logs carry an unbounded, unauthenticated header value.**
  - `app/Http/Middleware/VerifyPixelforgeWebhookSignature.php:33,40` log `Pixelforge-Event-Id` exactly as sent. AC1 rules out a rate limiter, so anyone can write header-sized values (about 8 KB through nginx) into `laravel.log` at warning or critical level. The JSON context encoding prevents forged lines, but it does not limit size.
  - Fix: cut the value to the 255 characters a real event id can have, for example with `Str::limit($request->header('Pixelforge-Event-Id'), 255, '')`.
- [ ] **S3. One subject is over the 72-character limit.**
  - `3d21aac [PE-008] Identify a snapshot without our generation id by its stored provider id` is 80 characters. The plan's task 4 already named it at that length, while tasks 6 and 12 were shortened.
  - The pipeline forbids rewriting history, so it can only be changed when the PR is prepared, for example to `[PE-008] Identify an id-less snapshot by its stored provider id`.
- [ ] **S4. The Pixelforge header names are repeated as string literals.**
  - `Pixelforge-Signature`, `Pixelforge-Event-Id`, `Pixelforge-Event-Type` and `Pixelforge-Delivery-Attempt` appear in the middleware (`:30,33,40`) and the controller (`HandlePixelforgeWebhookController.php:31-33`). PE-010's fake will need them too.
  - Guidelines §7 asks for constants over magic strings. Fix: public constants on `WebhookSignature` (`HEADER`) and on `WebhookDeliveryData` (the event headers), reused by the test trait.
- [ ] **S5. The AC5 test would miss a log line that holds part of the body.**
  - `tests/Feature/Webhooks/PixelforgeWebhookTransportTest.php:258` asserts only that the whole body is absent from each line. A context that dumped `data.object` or the usage block would still pass.
  - Fix: put a sentinel value that appears only in the body (for example a `metadata.note` or the prompt) in each row's body, and assert that it is absent too.
