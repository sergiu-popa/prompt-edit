# Review — PE-010-fake-pixelforge-driver

## Round 1

**Verdict: APPROVE**

Scope reviewed: the 19 `[PE-010]` commits `f5eeda3..a87b4da` on `pe-010-fake-pixelforge-driver` (`origin/main..HEAD`), against `story.md` and `plan.md`. That is the plan, 16 task commits, one Larastan fix-up and the bookkeeping.

Checks run on HEAD (`chain-make.sh chain check`, exit 0):
- Pint (206 files) and Larastan are clean.
- PHPUnit runs 1,082 tests with 33,565 assertions, all passing. This story adds 96 test methods.
- ESLint, Prettier and vue-tsc are clean, and Vitest runs 107 tests, all passing.

There is no Critical finding and no Warning. Four optional Suggestions follow.

### Summary

The implementation follows the plan class for class, and all 16 plan tasks are checked. All of the fake lives in `app/Support/Pixelforge/Fake/`, and its driver sits in `Drivers/` beside the HTTP one.

- **Binding and safety (AC 1–4):**
  - `AppServiceProvider` calls `refuseFakeDriverInProduction()` from both `boot()` and the `fake` arm. So in production with the fake, no request or artisan command runs. `RefusesFakePixelforgeInProductionTest` proves this in a real subprocess and checks the two allowed combinations.
  - The image controller checks the driver before the signature, so an inactive fake always answers 404. The PNG sits in `resources/`, outside `public/`.
  - The empty-secret guard runs first in `createGeneration()`, before any keyword, row or job. The sender also relies on `WebhookSignature::sign()` throwing, so nothing is ever signed with an empty key.
- **Create and idempotency (AC 5–8):**
  - The creation snapshot keeps the docs' key order. The column is `json` rather than `jsonb`, so the order survives storage. Every returned snapshot goes through `GenerationSnapshot::from()`.
  - `firstOrCreate` on the unique key falls through to `createOrFirst` (checked in `vendor/…/Eloquent/Builder.php:753`), so concurrent creates are race-safe.
  - A replay returns the stored snapshot as it is now. A changed body throws `IdempotencyKeyReusedException`, compared with `hash_equals`.
  - The cross-instance test uses `DatabaseTruncation` plus an explicit truncate, so committed rows never leak into the `RefreshDatabase` tests.
- **Keywords and chaos (AC 9–16):**
  - `FakeKeywords` matches with `stripos` on the bracketed keyword, which is ASCII case-insensitive and safe on UTF-8. The unit test pins the near misses, including a fullwidth `［fail］`.
  - The precedence is documented on the class and in D-35, and the unit and feature tests pin it. Rejections come before chaos 503, and both come before idempotency. The outcome order is `[fail]`, then `[succeed]`, then chaos.
  - `FakeChaos` short-circuits 0 and 100, so default settings never call `random_int`. The 200-create feature test asserts 400 requests with distinct event ids.
- **Lifecycle, usage and images (AC 17–20):**
  - `AdvanceFakeGenerationJob` saves the state before each send, and it re-dispatches itself with the row's own delay. `#[Tries(1)]` matches "no redelivery".
  - `FakeUsageGenerator` draws within the estimator's own `TokenBounds`, seeded from the key through `Xoshiro256StarStar`. So usage is reproducible and can never price above the hold. The property test (500 payloads) and the `estimates.json` fixture both confirm this.
  - Image URLs are root-relative temporary signed routes, so one URL loads in dev and in E2E. The per-row TTL makes `[expire]` affect only its own generation.
- **Webhooks (AC 21–23):**
  - There is one encoded body per event. Each copy is signed at its own `now()`, and the test travels between copies to prove the two `t` values differ.
  - The test pins the headers exactly and verifies the signature both through PE-008's verifier and with an independent `hash_hmac`.
  - A failed delivery logs `event_id`, `type`, `provider_id` and `status` (null for no response). The log never carries the body, signature or secret.
  - The round-trip tests replay the captured bytes and headers into the real endpoint, through `CreateGenerationAction` on the `sync` queue. They assert the exact `[hold, release, charge]` ledger, with the charge computed in the test, for the plain, `[duplicate]` and chaos-duplicated Lighthouse.
- **Environments (AC 24):**
  - `.env.example` defaults to `fake` with `http://app:8080`, and `LocalEnvTemplateTest` pins this. `.env.production.example` is unchanged.
  - `make setup` runs `webhook-secret` and `restart-worker`. The container names match `docker-compose.yml`.
  - The CI image builds the `ci` stage, whose `.env` comes from `.env.example` and gets the generated secret. Its app container is named `app` on the job network, so `http://app:8080` resolves there.
  - `phpunit.xml` forces `http` and an empty secret, so the fake `.env` in the CI image never changes which client PE-009's tests get.
  - The command refuses production, keeps any existing value, and never prints a value.

No public API response is added, so the constant-field check does not apply. There are no queries in loops, and the only lookups are by primary or unique key.

### Commit hygiene

- Each task commit carries its code with its tests. The decisions (`1cf2fcc`) and the bookkeeping (`a87b4da`) have their own commits.
- All subjects start with `[PE-010]` and are at most 72 characters. Every message ends with the trailer.
- One exception: `5c7b3ca` is a fix-up for `520ca16`. That commit's test passes `preg_replace()`'s `?string` straight to `get()`, so `520ca16` on its own fails `make stan`. See S1.

### Suggestions

Optional; none blocks QA.

- **S1 (process, acknowledged).** `520ca16` does not pass `stan` on its own, and `5c7b3ca` repairs it (`tests/Feature/Pixelforge/Fake/FakePixelforgeImageTest.php:61`). That breaks "every commit passes its checks" and bisectability for one commit. No action now, because fixing it needs a history rewrite, which the pipeline forbids. In future, run the full `check` (including `stan`) before each commit, not only `test`.
- **S2.** `app/Support/Pixelforge/Drivers/FakePixelforgeClient.php:102-111` passes `firstOrCreate` an eager array. So every replay, and every reused-key request, still runs the estimator and rolls `failsGeneration()` and `duplicatesWebhooks()`, then throws the results away. The behaviour is right, but `FakeChaos.php:10-12` says those two rolls are made "once". Pass the values as a closure (`firstOrCreate([...], fn (): array => [...])`, which `Builder::firstOrCreate` accepts). The estimate and the rolls then run only when a row is really inserted, and the docblock becomes true.
- **S3.** In `app/Support/Pixelforge/Fake/AdvanceFakeGenerationJob.php:46-50`, the next step is dispatched after `deliver()`. Anything `deliver()` throws therefore leaves the row in `processing` for good. Today that can only be `WebhookSecretNotConfiguredException`, if the secret is cleared while a generation is in flight. The local generation then keeps its hold until PE-014. Wrapping the send as `try { $sender->deliver(…); } finally { self::dispatch(…)->delay(…); }` keeps the webhook order on the `sync` queue and still advances the lifecycle. Alternatively, leave it and note it in the class docblock.
- **S4.** The `app/Support/Pixelforge/Fake/FakeKeywords.php:14-16` docblock reads "ends as `[fail]`, else `[succeed]`, decides", which does not parse. Suggested: "An accepted create's outcome is decided by `[fail]`, else `[succeed]`; a chaos `internal_error` follows them, …".
