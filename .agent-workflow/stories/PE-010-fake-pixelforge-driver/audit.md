# Audit — PE-010-fake-pixelforge-driver

**Verdict: PASS**

Run on branch `pe-010-fake-pixelforge-driver` from `aadc9fe` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (APPROVE, round 1) and `qa.md` (QA PASSED) present.

## Scope

I checked the whole `[PE-010]` diff (`git diff 9709074..aadc9fe`, 41 files outside `.agent-workflow`) against `story.md` AC 1–24, `plan.md`, review round 1, QA, requirements §2.1–§2.7 and D-34 to D-36. That covers:
- `FakePixelforgeClient`, `FakePixelforgeGeneration` and its migration, `FakeGenerationOutcome`, `FakeKeywords`, `FakeChaos`, `FakeUsageGenerator`, `FakeWebhookSender` and `AdvanceFakeGenerationJob`;
- the `AppServiceProvider` binding and boot guard, `PixelforgeNotConfiguredException`, `ShowFakePixelforgeImageController`, `routes/fake-pixelforge.php` and `bootstrap/app.php`;
- `config/pixelforge.php`, `.env.example`, `phpunit.xml`, the `Makefile`, the Dockerfile `ci` stage, the CI E2E job, the README and `GenerateLocalWebhookSecretCommand`;
- the `InteractsWithFakePixelforge` concern and every new or changed test.

The Laravel internals the design relies on were read in `vendor/`: `Builder::firstOrCreate()` / `createOrFirst()` (savepoint, then a re-read on the write PDO after a unique violation) and `Env::writeVariable()`. Where the auditor checklist mentions Livewire, the Inertia/Vue rules apply instead. This story has no page or prop, so they have nothing to check. PR size is not a finding (`.agent-workflow/CLAUDE.md` § Git hygiene).

## What was verified

- **Production can never bind or reach the fake.**
  - `AppServiceProvider::boot()` throws `fakeDriverInProduction()` when `APP_ENV=production` and `pixelforge.driver` is `fake`, so no request, worker, scheduler or artisan command runs. The `fake` arm of the binding checks again, so a runtime `config([...])` cannot get past it.
  - Both checks read config, not `env()`, so they hold with a cached config. A config cached with `fake` refuses to boot. An env override after caching is ignored, and the cached driver wins.
  - Any other spelling (`FAKE`, ` fake`) is an unknown driver, which the guard does not need to catch, because the binding refuses it.
  - Nothing in `app/` resolves `FakePixelforgeClient` directly; only the test concern does.
  - The image route is registered everywhere, but it answers 404 unless the driver is `fake`, even with a valid signature. The PNG sits outside `public/`.
  - The production Docker stage is built from `base`, not `ci`, so it never runs `app:generate-local-webhook-secret`. The command refuses production anyway. `.env.production.example` has no `PIXELFORGE_*` line, which `ProductionEnvTemplateTest` pins.
- **Race-safe idempotency.** `firstOrCreate` on the unique `idempotency_key` falls through to `createOrFirst`, which inserts inside a savepoint and re-reads the row on the write connection after a unique violation. So two concurrent creates give one row: the second one gets a replay or a 409. Only the inserting call dispatches the lifecycle job. `SubmitGenerationAction` calls the client outside any transaction, and the database queue's `after_commit` is false, so the job row commits with the fake's row.
- **Webhooks are signed as Pixelforge signs them, and go through PE-008's endpoint.** The body is encoded once. Each copy is signed by PE-008's `WebhookSignature::sign()` at its own `now()`, which is `hex(HMAC-SHA256(secret, "<t>.<body>"))` as §2.5 gives it. The POST goes over HTTP to `http://app:8080` + `route('webhooks.pixelforge')`, so it passes the real middleware, verification, dedupe and ledger. Redirects are never followed. In CI, the app container is named `app` on the job network, so the same URL resolves there.
- **A charge never goes above the hold.**
  - The hold is the estimate's `user max` (`CreateGenerationAction`), computed from the same prompt, quality, size and reference count that the payload carries.
  - The fake's usage comes from the same `EstimateGenerationCostAction`: image and output are the exact lines, and text and reasoning are drawn within the lines' `TokenBounds`.
  - The fake's `rates` are `pricing.rates`, the table the estimator prices with. So the provider cost is at most the estimate's max, and so is its marked-up charge.
  - The only charge above a hold is the rate change between estimate and creation (`SettleGenerationCreditsAction`), which the story allows and the fake cannot cause.
  - `FakeUsageGeneratorTest` checks this over 500 seeded payloads and the `estimates.json` fixture.
- **Keyword semantics follow §2.7.**
  - Matching is ASCII case-insensitive, anywhere in the prompt, with the brackets.
  - `[blocked]` gives a 400 `content_policy_violation` and `[overloaded]` a 503 with `Retry-After: 2`, and neither stores anything.
  - `[fail]` is accepted, then sends `generation.processing` and `generation.failed`. `[succeed]` rules out only a random failure. `[duplicate]` sends every webhook twice under one event id.
  - `[slow]` is longer than normal processing. The docs' 60–90 s is scaled down by config, as the story asks, because E2E uses the dev containers.
  - The precedence matches D-35 and is pinned by unit and feature tests.
- **No real API call, and no secret in logs.**
  - `Http::preventStrayRequests()` runs for every test. The fake's tests fake only `http://app:8080/webhooks/pixelforge`. `phpunit.xml` forces the driver to `http` and both the key and the secret to empty. The production-boot subprocess runs `artisan list`, which makes no request.
  - The sender logs only `event_id`, `type`, `provider_id` and `status`, and a test asserts that neither the body, the signature nor the secret is logged.
  - The secret is `#[SensitiveParameter]` in the client. The job payload carries only the `gen_` id. The secret command prints neither the old nor the new value. No `pf_…` or `whsec_…` value appears in the check logs.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | The processing step dispatched the terminal step only after `deliver()` returned. A throw from `deliver()` (a secret cleared while a generation was in flight) left the fake's row in `processing` for good, so a replay could never see it end (review S3). | `app/Support/Pixelforge/Fake/AdvanceFakeGenerationJob.php:46-56` | Suggestion | Fixed | The send is wrapped in `try … finally`, and `finally` dispatches the next step with the row's delay. The webhook order on the `sync` queue is unchanged, and the exception still fails the job, so it is logged. The new test `test_a_webhook_that_cannot_be_signed_never_stalls_the_lifecycle` failed before the change, with one job pushed instead of two. After it, the row ends `succeeded` once the secret is back (`73d43e8`). |
| 2 | `firstOrCreate` got an eager values array. So every replay and every reused-key request also ran the estimator and rolled `failsGeneration()` and `duplicatesWebhooks()`, then threw the results away. `FakeChaos`'s docblock ("the failure and the duplicates once") was not true (review S2). | `app/Support/Pixelforge/Drivers/FakePixelforgeClient.php:102` | Suggestion | Fixed | The values are now a closure, which `Builder::firstOrCreate()` evaluates only when it inserts. Behaviour is unchanged, and the existing create, replay, reuse and chaos tests pass (`1cf5c76`). |
| 3 | The image was served with `Cache-Control: public` and a `Last-Modified`, so a browser could cache it heuristically and show it after expiry. AC 20 says an expired URL answers an error, like a dead signed URL. | `app/Http/Controllers/ShowFakePixelforgeImageController.php:27` | Suggestion | Fixed | The response now sends `Cache-Control: no-store, private`, so every load reaches the signature check. The new test `test_the_image_is_never_cached` failed before the change (`e0a833b`). PE-012 still decides "expired" from `image_url_expires_at` (requirements §4), so the app never depends on the 403. |
| 4 | The precedence sentence in the `FakeKeywords` docblock did not parse (review S4). | `app/Support/Pixelforge/Fake/FakeKeywords.php:14-16` | Suggestion | Fixed | It now reads "An accepted create's outcome is decided by `[fail]`, else `[succeed]` …" (`184e739`). |
| 5 | `520ca16` does not pass `stan` on its own; `5c7b3ca` repairs it (review S1). | `tests/Feature/Pixelforge/Fake/FakePixelforgeImageTest.php:61` | Suggestion | Skipped | Fixing it needs a history rewrite, which the pipeline forbids. Every audit commit passed the full `check` before it was made. |
| 6 | The production guard sits in the `PixelforgeClient` binding and in `boot()`, not in `FakePixelforgeClient` itself. A direct `app(FakePixelforgeClient::class)` would skip the binding's check. | `app/Providers/AppServiceProvider.php:51-56` | Suggestion | Skipped | No code in `app/` resolves the concrete class, only the test concern does. In production, `boot()` already refuses to start whenever the driver is `fake`. The job and the image route cannot act without a fake row or the `fake` driver. |
| 7 | Duplicate copies both carry `Pixelforge-Delivery-Attempt: 1`. | `app/Support/Pixelforge/Fake/FakeWebhookSender.php:77` | Suggestion | Skipped | The docs give no attempt number for a chaos duplicate, and nothing in PE-008 reads the header for logic. Each copy is still a first delivery of the same event, as the story describes it (AC 14). |

No Critical or Warning issues were found.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `chain-make.sh chain check` | Exit 0. Pint passes on 206 files, and Larastan reports no errors. PHPUnit: 1,084 passed (33,582 assertions), up from 1,082 by the two new tests. ESLint, Prettier and vue-tsc are clean, and Vitest runs 107 passed in 9 files. |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-010/audit-fix4.log`. Every test used `Http::fake`, and no real Pixelforge request was made. `make e2e` and the browser load of the placeholder image stay deferred to the orchestrator's run on the main stack, as QA recorded.
