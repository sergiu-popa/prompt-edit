# Audit — PE-008-verified-idempotent-webhooks

**Verdict: PASS**

Run on branch `pe-008-verified-idempotent-webhooks` from `b12bfe8` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (APPROVE, round 1) and `qa.md` (QA PASSED) present.

## Scope

I checked the whole `[PE-008]` diff (`git diff 9885826..b12bfe8`) against `story.md` AC1–27, `plan.md`, the review, QA, and the Pixelforge webhook docs (https://pixelforge.promptedit.dev/docs#webhooks). That covers:
- `WebhookSignature`, its enum and exceptions, and `config/pixelforge.php`;
- `routes/webhooks.php`, `bootstrap/app.php`, `VerifyPixelforgeWebhookSignature` and `HandlePixelforgeWebhookController`;
- `WebhookDeliveryData`, `InvalidWebhookDeliveryException`, `WebhookEventType`;
- the `pixelforge_webhook_events` migration, `PixelforgeWebhookEvent`, `HandlePixelforgeWebhookAction`, and the D-27 change to `TransitionGenerationAction`;
- the README section, D-27 to D-30, the three test concerns, the child script and every test file.

Where the auditor checklist mentions Livewire, the Inertia/Vue rules apply instead. This story has no page or prop, so they have nothing to check. PR size is not a finding (`.agent-workflow/CLAUDE.md` § Git hygiene).

## What was verified

- **Signature over the raw body.**
  - `verify()` runs on `$request->getContent()`. Nothing in the webhook path reads `input()` or `json()`, and the padded-string ping proves TrimStrings never reaches the signed bytes.
  - The HMAC is hex `hash_hmac('sha256', "<t>.<body>", $secret)` over the header's `t` as sent. It is compared with `hash_equals(expected, given)`, in constant time and in the right argument order.
  - The ±300 s tolerance applies both ways, with `abs()`. `t` is 1–18 digits, so the cast and the subtraction can never overflow. The signature is checked before the age, so `stale` always means a genuine old delivery.
  - Malformed headers are refused: no `t`, no `v1`, an empty `v1`, a non-digit or negative `t`, a part without `=`, a trailing comma, and a repeated `t` or `v1`.
- **Several `v1` entries.** The docs give only `t=<unix>,v1=<hex>` and say nothing about several signatures or rotation. The story leaves them out of scope, and the plan refuses a repeated `v1` as `malformed`. That fails closed and logs the reason (see #6).
- **No secret, nothing accepted.** A null or `''` secret throws before any header is read, from both `sign()` and `verify()`. So the HMAC never runs with an empty key, and every request gets 500 and a critical log. This includes a request signed with an empty key (AC6 matrix).
- **No CSRF, session or state before verification.**
  - The route loads through `withRouting(then:)` with no group, and its gathered middleware is exactly `[VerifyPixelforgeWebhookSignature]`, which a test asserts. No cookie is set.
  - The global middleware (TrustHosts, TrustProxies, HandleCors, maintenance, ValidatePostSize, TrimStrings, ConvertEmptyStringsToNull) writes nothing.
  - The controller runs only after the signature middleware passes.
- **Idempotency by event id.**
  - `event_id` is `unique`. The row is written with `fillAndInsertOrIgnore` (`ON CONFLICT DO NOTHING`) inside the same `DB::transaction` as the transition.
  - Only an inserted row reaches `TransitionGenerationAction`. The generation is locked `FOR UPDATE` before the insert, so two different events for one row cannot deadlock on the foreign key's key-share lock.
  - The two-process race and its rollback branch both end with one row and one charge.
  - A failed apply rolls the event row back with the status and ledger, and a redelivery charges once (AC23).
- **Out of order and duplicates.** These are forward-only through PE-006's ranks: processing after succeeded, failed after succeeded and succeeded after a provider failure change nothing. There is a single charge through PE-007's `unique(generation_id, type)`, including two event ids carrying one snapshot (AC11) and D-09.
- **Response codes.** A 2xx goes only to an applied event, a duplicate, a ping, an unknown type, an unknown generation, or an identity, out-of-order or D-09 refusal. In each of those the event was recorded on purpose. A bad signature and an unreadable signed body get 400, with nothing persisted. A missing secret and an unexpected failure get 500, with everything rolled back. There is no Form Request, so nothing can answer 302.
- **Logs.**
  - Rejections log the reason and the header event id. Unreadable bodies log the field path, the event id and `generation_id`. Unknown generations log the ids.
  - No log carries the secret, the signature, the body, the prompt or the signed image URL. After #2 this is pinned across nine outcomes, and a mutation that logs part of the body now fails the test.
  - The unsigned header values are capped at 255 characters (#1).
- **DoS-ish inputs.**
  - An unsigned body is only hashed, which is linear. It is never decoded, because the signature fails first.
  - A signed body is decoded with `json_decode`'s default depth of 512 and `JSON_THROW_ON_ERROR`, so a deeper body is a 400 `notJson`. That is pinned now (#3).
  - Body size is bounded by ValidatePostSize (`post_max_size` 8M) before the controller runs (#7).
  - No value reaches Postgres unchecked:
    - a non-ULID `generation_id` is never queried;
    - an id or type with a NUL byte, or longer than 255 characters, is refused;
    - the attempt is clamped to `integer`.
- **No outbound HTTP.** The handler is DB-only. Every webhook test calls `Http::preventStrayRequests()`, and AC8 asserts `Http::assertNothingSent()`.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | The rejection logs (warning and critical) wrote the unsigned `Pixelforge-Event-Id` header exactly as sent. The route has no rate limiter (AC1) and no authentication before the log, so anyone could write header-sized values (about 8 KB each) into `laravel.log`. The post-verification header-mismatch warning was also unbounded. Review S2. | `app/Http/Middleware/VerifyPixelforgeWebhookSignature.php:33,40` | Warning | Fixed | A private `loggedEventId()` cuts the header to `WebhookDeliveryData::MAX_FIELD_LENGTH` (255, now public), which is the longest a real event id can be. `logHeaderMismatches()` cuts the logged header the same way. The comparison itself still uses the full value. There are three new tests: a 4,000-character header on a `missing` rejection, on the unconfigured-secret path, and on a header mismatch. All three failed before the change and pass after it (`3981e03`). |
| 2 | The AC5 test asserted only that the whole body was absent from each log line, so a context that dumped part of the body, such as `data.object`, would have passed. Review S5. | `tests/Feature/Webhooks/PixelforgeWebhookTransportTest.php:258` | Suggestion | Fixed | The test now also asserts, for every outcome, that no line carries the generation's prompt (personal data) or the signed image URL, which appear only in the body. A temporary mutation that logged `substr(body, 5)` on an unreadable snapshot failed the test, and was reverted (`82299fe`). |
| 3 | There was no test for a signed body nested beyond `json_decode`'s depth limit. | `tests/Feature/Webhooks/PixelforgeWebhookEventsTest.php:49` | Suggestion | Fixed | A new AC7 data row nests `data` 600 arrays deep. It gets 400 with one error log and nothing persisted (`6ccff20`). |
| 4 | The four Pixelforge header names were repeated as string literals in the middleware, the controller and the test trait. PE-010's fake will need them too. Review S4. | `app/Http/Controllers/HandlePixelforgeWebhookController.php:31-33` | Suggestion | Fixed | `WebhookSignature::HEADER` and `WebhookDeliveryData::EVENT_ID_HEADER`, `EVENT_TYPE_HEADER` and `DELIVERY_ATTEMPT_HEADER` are used by the middleware, the controller and `DeliversPixelforgeWebhooks`. The tests' data rows keep the literal names on purpose, because they pin the wire format (`0ddc71c`). |
| 5 | D-30 said a redelivery is "never a second warning", but a header that disagrees with the body is logged on every delivery, duplicates included. That behaviour is intended and tested. Review S1. | `.agent-workflow/requirements.md:242` | Suggestion | Fixed | The row now says the header mismatch is the one warning logged again, and why. `logHeaderMismatches()`'s new docblock says the same (`11719c0`). |
| 6 | Several `v1` entries in one header are refused as `malformed`, while the docs' Laravel example would check the last one. | `app/Support/Pixelforge/WebhookSignature.php:98-100` | Suggestion | Skipped | The docs do not document several signatures or rotation, and the story puts both out of scope. Refusing fails closed, and every refusal is logged with its reason, so a format change would show up straight away. Pixelforge's retries are bounded, and PE-014's poller recovers the generations. Accepting any matching `v1` would be new behaviour against the plan's tested rule. |
| 7 | The app sets no body-size cap of its own on the webhook route. | `app/Http/Middleware/VerifyPixelforgeWebhookSignature.php:30` | Suggestion | Skipped | ValidatePostSize, which is global, refuses a `Content-Length` over `post_max_size` (8M) before the route runs, and every other POST route has the same bound. An unsigned body is only hashed, never decoded. A real snapshot is a few KB: the prompt is at most 4,000 characters, and there are at most 20 × 500 metadata characters and 4 reference URLs. The edge limit is a PE-015 production setting. |
| 8 | Commit `3d21aac` has an 80-character subject, over the 72 limit. Review S3. | `3d21aac` | Suggestion | Skipped | This pipeline never rewrites history. It can be reworded when the PR is prepared, for example to `[PE-008] Identify an id-less snapshot by its stored provider id`. |

No Critical issues were found.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `chain-make.sh chain check` | Exit 0. Pint passes on 148 files and Larastan reports no errors. PHPUnit: 749 passed (3,651 assertions), up from 745 by the four new tests and rows, with the concurrency tests included. ESLint, Prettier and vue-tsc are clean, and Vitest runs 107 passed in 9 files. |
