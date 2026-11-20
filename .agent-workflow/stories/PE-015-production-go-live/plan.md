# Plan — PE-015-production-go-live

## Approach
An opt-in PHPUnit group `live`, excluded in `phpunit.xml` and run only by `make live-smoke`, drives `HttpPixelforgeClient` against the real API with the dev key its guard reads from `.env`, and checks pricing, idempotency and usage against PE-001's pinned pricing and PE-002's estimator. The hardening is small code changes, each with a feature test: a reconciler heartbeat and health check, a per-IP limit on new accounts (registration stays open, D-57), a redirect with a notice for a 419 or an expired session, a closed password-confirmation endpoint, UTF-8-safe names, production's Pixelforge names in the env template, and no unused production Docker stage. Everything that needs production (the key, the webhook, the deploy, the eight real generations) is a post-merge checklist for the orchestrator, backed by a read-only `app:check-generation-money` command and the README's reviewer guide and per-environment runbook.

## Tasks
Each task is one TDD commit. Write the failing test first, then the code, and get `make check` green before committing.
- A task that reads a new env name in config clears it in `phpunit.xml`, in both `<env … force="true">` and `<server>`, in that same commit (D-48).
- After task 8 changes `docker-compose.yml`, run `docker compose up -d`.
- After any task that changes command, action or config code (4, 8, 17), run `make restart-worker` before `make e2e`.
- Run `make e2e` for tasks 8 and 12, and as the final check after task 21.
- Run `make live-smoke` once when tasks 1, 2 and 3 are green. Any agent may run it, but it shares Pixelforge's per-account limits with production, so never loop it.
- Task 12 uses the `tailwindcss-development` skill.

Tasks 1–13 are the code half: AC 1–8, 11 (local), 15, 18, 20, 21 and the deferred audit items. Tasks 14–21 are the operations half: the env template, Docker, the money check, the smoke rows, the README and the decisions. The cut point is after task 13 (see Size).

- [x] 1. Add `<groups><exclude><group>live</group></exclude></groups>` to `phpunit.xml`. Add `LiveSmokeGuard` and `LivePixelforgeSmokeTest` (`#[Group('live')]`), with its `setUp()` and the AC 3 pricing test (Tests). Add the Makefile target `live-smoke`, which runs `php artisan test --compact --group=live` in the app container with a comment naming D-58. The target echoes nothing. Test-first: `LiveSmokeGuardTest`. Commit: `[PE-015] Add the opt-in live smoke, its guard and the pricing check`
- [x] 2. Add the AC 2, 4, 5 and 6 tests to `LivePixelforgeSmokeTest`: create, replay and a reused key. Add its private send helper that retries a `503` or `429` up to 3 times after `Retry-After`. Commit: `[PE-015] Prove create, replay and key reuse against the live API`
- [x] 3. Add the AC 7 and 8 tests: poll until terminal, then check usage, rates and the cost line printed to STDERR. Commit: `[PE-015] Poll the live generation and check its usage and cost`
- [x] 4. Add `ReconcilerHeartbeat` (Backend). `ReconcileGenerationsCommand::handle()` records the run after the action returns. Update its docblock. Test-first: `ReconcileGenerationsCommandTest` rows. Commit: `[PE-015] Record the reconciler's last successful run`
- [x] 5. Add `routes/health.php`, loaded in `bootstrap/app.php`'s `then:` next to the webhooks, and `ShowReconcilerHealthController`. Test-first: `ReconcilerHealthTest`. Commit: `[PE-015] Expose the reconciler's health at /health/reconciler`
- [x] 6. Move `StorableTextRule` from `App\Domain\Generation\Rules` to `App\Domain\Shared\Rules` and update `CreateGenerationRequest`'s import. This is a refactor with no behaviour change: `CreateGenerationValidationTest` passes unchanged. Commit: `[PE-015] Move StorableTextRule to the Shared domain`
- [x] 7. Add `StorableTextRule` to `CreateNewUser`'s `name` rules. The rule's message uses `:Attribute`, so a name reads "Name contains characters that can't be sent.", while the prompt and reference copy stays the same. Its docblock now covers names. Test-first: `RegistrationTest` rows. Commit: `[PE-015] Refuse a name that can't be stored at sign-up`
- [x] 8. Add `auth.registrations_per_hour` (Config) and the limit in `CreateNewUser` (Backend). Clear `AUTH_REGISTRATIONS_PER_HOUR` in both places of `phpunit.xml`. In `docker-compose.yml`, rename the anchor `x-pixelforge-dev-bounds` to `x-dev-bounds` (its comment and 4 merges) and add `AUTH_REGISTRATIONS_PER_HOUR: ${AUTH_REGISTRATIONS_PER_HOUR:-1000}`. Add `AUTH_REGISTRATIONS_PER_HOUR=1000` with a one-line comment to `.env.example`. Test-first: `RegistrationThrottleTest` and `LocalEnvTemplateTest` rows. Commit: `[PE-015] Limit new accounts to 3 an hour per IP`
- [x] 9. In `FortifyServiceProvider`, register `Fortify::confirmPasswordsUsing()` with a callback that aborts 404, like the existing `confirmPasswordView`. Update the comment there. Test-first: `FortifyRoutesTest` rows. Commit: `[PE-015] Close the unused password confirmation endpoint`
- [x] 10. Add rows to `LoginThrottleTest` and `CreateGenerationThrottleTest` (AC 15). No production change is expected. Commit: `[PE-015] Pin the sign-in and generation throttles for go-live`
- [x] 11. Add `RedirectExpiredSession` (Backend). Register it with `$exceptions->respond()` in `bootstrap/app.php`, with a comment naming D-60. Add `notice?: string` to `flashDataType` in `types/inertia.d.ts`. Test-first: `ExpiredSessionTest`. Commit: `[PE-015] Send an expired session back with a notice instead of a 419`
- [x] 12. Add `components/ui/Notice.vue`, and render the flash `notice` in `AuthLayout` and `AppLayout` (Frontend). Test-first: `Notice.test.ts`, `AuthLayout.test.ts`, `AppLayout.test.ts` rows and the `auth.spec.ts` E2E test. Commit: `[PE-015] Show the session-expired notice on every layout`
- [x] 13. Add `ProductionErrorPagesTest` (AC 11). No production change is expected. Commit: `[PE-015] Prove error pages leak nothing with production settings`
- [x] 14. In `.env.production.example`, add a Pixelforge block: `PIXELFORGE_DRIVER=http`, a blank `PIXELFORGE_API_KEY=` and a blank `PIXELFORGE_WEBHOOK_SECRET=`, with one-line comments (production's own key, never the dev key; the secret of that key). Drop the header's "No PIXELFORGE_* until PE-015" line. Test-first: `ProductionEnvTemplateTest` rows. Commit: `[PE-015] Add production's Pixelforge names to the env template`
- [x] 15. Delete the `production` stage of `.docker/Dockerfile` and the file it alone copies, `.docker/development/conf.d/npm.conf`. Keep `production-frontend`, which `ci` uses. Update the README's Project Layout line to "development and ci; production deploys with Deployer". The commit body says that CI builds and pushes only `--target ci` and that the stage baked `.env.example`'s fake driver and dev bounds into `.env`. Check with `docker build --check -f .docker/Dockerfile .`; CI's build proves `ci` still works. Commit: `[PE-015] Remove the unused production Docker stage`
- [x] 16. Add `CheckGenerationMoneyAction` (Backend). Test-first: `CheckGenerationMoneyActionTest`. Commit: `[PE-015] Check a generation's money against its ledger and estimate`
- [x] 17. Add `CheckGenerationMoneyCommand` (`app:check-generation-money`) (Backend). Test-first: `CheckGenerationMoneyCommandTest`. Commit: `[PE-015] Add app:check-generation-money for the real generations`
- [x] 18. Add two rows to `tests/smoke/production.spec.ts`: `/health/reconciler` answers 200 with `status` `ok`, and an unsigned `POST /webhooks/pixelforge` answers 400 with no `Location` header (AC 10: no redirect, and the secret is set). Update the spec's docblock. Only `make js-check` applies, because the smoke runs against production after merge. Commit: `[PE-015] Smoke-check the reconciler and the webhook URL on production`
- [x] 19. Add the README reviewer guide (AC 24, README). Add `make live-smoke` to the Testing table and to Make Targets → Quality. Commit: `[PE-015] Add the reviewer guide to the README`
- [x] 20. Add the README operations runbook and go-live checks (AC 25, README). Rewrite "Pixelforge on production", drop "Don't submit generations on production before PE-015", and update the Status row. Check every command against `deploy.php`, `.env.production.example` and requirements §2. Commit: `[PE-015] Add the Pixelforge runbook and go-live checks to the README`
- [x] 21. Add D-57 to D-61 to `requirements.md` §5 (Decisions log). Commit: `[PE-015] Record the go-live decisions`

## Story clarifications
- **What this PR delivers vs. what happens after merge.** The user's answer was "merge first, check after".
  - **Code in this PR:** AC 1–8, 11 (local), 15, 18, 20, 21, 24, 25, plus the tooling the manual checks use (tasks 16–18). AC 12, 14 and 16 already have tests: `RefusesFakePixelforgeInProductionTest`, `HttpsBehindHestiaProxyTest` and the PE-013 order test in `SubmitGenerationDispatchTest`.
  - **Manual production checks after merge:** AC 9, 10, 11 (production), 12, 13, 14, 16, 17, 18 (production), 19 (production), 22 and 23. The orchestrator runs them (Manual production checks). No agent in the pipeline uses SSH, `dep`, `make smoke-production` or production access. The PR description lists these checks unticked as "after merge", and the results go into a PR comment.
- **The live smoke.**
  - Its key is `live-smoke-` + `Str::uuid()`. Lowercase letters and hyphens are outside Crockford base32, so it can never be a ULID. It is also sent as `metadata.generation_id` through the real `CreateGenerationPayloadData`.
  - The prompt is `[succeed] live smoke ` + 30 × U+1F305. The test pins its length at 51 code points and 81 UTF-16 units.
  - The estimator's bounds (11–15) and its max provider cost (17,525,000 n$) come from `EstimateGenerationCostAction`, never hard-coded alone.
  - Pixelforge has random `503`s even for `[succeed]`, so each create retries a `503` or `429` up to 3 times after `Retry-After`. Nothing is created by either, and the key stays the same.
  - A poll that fails (ambiguous or `429`) waits for the next 5 s tick, inside the 3 min cap.
  - One run sends at most about 40 requests: pricing, about 3 creates, and up to 36 polls.
- **What the smoke may print.** It prints one STDERR line: the provider id, the four token counts, and the cost in n$ and USD (`toUsdString()`) beside the 17,525,000 n$ max. Raw responses come from `Http::record()`, and only their status, the `Idempotent-Replayed` header and the body are read. A recorded request is never dumped. No data provider ever carries the key.
- **The pricing diff (AC 3).** The live document must have `object` `pricing`, `currency` `usd` and `unit` `per_1m_tokens`. Its `rates` and `tokens` must equal `config('pricing')` with `text_token_variance_percent` mapped back to `text_token_variance` = percent ÷ 100. Compare key-sorted copies with `assertSame`, so key order never matters and types always do.
- **The account limit (AC 20, D-57).** It counts accounts **created** per IP, not attempts.
  - `CreateNewUser` refuses before validation once the IP has used its limit, with a field error on `email`. The copy is "Too many accounts were created from this network. Please try again in N minutes.", with N = the limiter's seconds rounded up to minutes, at least 1, and "minute" when N is 1.
  - It hits the limiter (3,600 s decay) only after `User::create()`.
  - Concurrent sign-ups can overshoot by the number in flight. That is accepted: spend is still bounded per user (D-39) and by Pixelforge's cap.
  - The key is `registrations:` + `request()->ip()`. Production's IP resolution is D-21's, already checked by PE-004's "Client IP" step.
- **Laravel 13 and 419s (AC 21, D-60).** `PreventRequestForgery` passes any request with `Sec-Fetch-Site: same-origin` without a token. In a modern browser over HTTPS, an expired session therefore reaches `auth` as a guest, and a 419 needs a browser without fetch metadata or a cross-site post. Both paths get the notice:
  - **A 419:** a 303 to the Referer when its host is the request's host, otherwise Sign in, with `Inertia::flash('notice', …)`. A request that `expectsJson()` and has no `X-Inertia` header keeps its 419.
  - **An `AuthenticationException` on an unsafe method:** the existing redirect to Sign in, which keeps its intended URL, gains the same flash. A guest's GET of `/dashboard` gets no notice.
  - Inertia's middleware re-flashes on redirects, so the notice survives `/dashboard` → `/login`.
  - The app keeps one `respond()` callback. Inertia's `handleExceptionsUsing()` would replace it, so a future custom error page must go through this class.
- **Password confirmation (D-61).** `POST /user/confirm-password` stays registered, because `FortifyRoutesTest` pins Fortify's routes. It runs Fortify's `nullable|string` validation, then aborts 404 for every password, so no password is ever checked and nothing is stored in the session. The guest redirect to Sign in is unchanged.
- **Names.** `StorableTextRule` moves to Shared, because Generation and auth now both use it. Emails need nothing: `Str::lower` already replaces bad bytes (PE-003 audit #9).
- **Reconciler health (AC 18, D-59).**
  - A "successful run" is `ReconcileGenerationsAction::execute()` returning. A row that throws inside it is logged and still counts as a run; the action itself throwing records nothing.
  - Stale means more than 300 s old (300 s is ok), or never recorded.
  - The endpoint exposes only `status` (`ok` or `stale`) and `last_succeeded_at` (ISO-8601 Zulu or null), with `Cache-Control: no-store`. It sets no cookie and has no session.
  - A run that takes over 5 min (it could take up to 20 polls × 35 s) reads stale until it ends. That is a fair signal.
- **AC 15** is already proven by PE-003's `LoginThrottleTest` and PE-011's `CreateGenerationThrottleTest`. Task 10 only adds the missing assertions: the 6th wrong password is not a 429 and renders inline, and the 11th generation writes no hold and pushes no job.
- **AC 11, local half.** A route registered inside the test throws, so no debug route ships. The test runs as production with debug off, on the production host, and resets Symfony's static trusted hosts in `tearDown()`, as `HttpsBehindHestiaProxyTest` does.
- **Production config.** Production's `shared/.env` gains exactly `PIXELFORGE_DRIVER=http`, `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`. The only new setting, `AUTH_REGISTRATIONS_PER_HOUR`, defaults to production's 3, so it is not in the template.
- **Decision numbers.** New rows are D-57 to D-61. Task 21 records them, so the plan commit touches only `plan.md` and `story.md`.

## Config
| Key | Env | Default | Dev / E2E | Task |
| --- | --- | --- | --- | --- |
| `auth.registrations_per_hour` | `AUTH_REGISTRATIONS_PER_HOUR` | 3 (`filter_var`, min 1, else the default) | 1,000, from the compose anchor and `.env.example` (E2E registers about 30 users a run, and repeated runs share the hour) | 8 |

- `CreateNewUser` reads the limit and `request()->ip()` at call time. Fortify binds the creator as a **singleton** (`createUsersUsing`), so neither may be constructor-injected.
- `ReconcilerHeartbeat` uses the pinned store `pixelforge.rate_limit_store` (`database`). Its docblock is widened to name the heartbeat as well as the budget and the create lock.

## Backend
All classes are `final` and declare `strict_types`.

| Class / file | Location | Role |
| --- | --- | --- |
| `LiveSmokeGuard` | `tests/Feature/Pixelforge/Live/` | Built from three inputs: `.env`'s parsed values (`Dotenv::parse`; a missing file means none), the process's `CI` (`getenv`), and the app's environment.<br>- `fromRuntime(Application)` reads them.<br>- `client(Repository $config): HttpPixelforgeClient` refuses first, with a `RuntimeException`: production (the app or `.env`'s `APP_ENV`), then CI (set and non-empty), then a missing or empty `PIXELFORGE_API_KEY`.<br>- Only then does it build the client from the key and `pixelforge.base_url` and the timeouts.<br>- No message contains the key.<br>Consumer: `LivePixelforgeSmokeTest`. |
| `ReconcilerHeartbeat` | `app/Support/Health/` | Built like `PixelforgeCreateLock`: a cache factory plus `#[Config('pixelforge.rate_limit_store')]`.<br>- `KEY` and `STALE_AFTER_SECONDS` (300);<br>- `recordSuccessfulRun(CarbonImmutable)` stores unix seconds forever;<br>- `lastSuccessfulRunAt(): ?CarbonImmutable`;<br>- `isStale(CarbonImmutable $now): bool`.<br>Consumers: `ReconcileGenerationsCommand`, `ShowReconcilerHealthController`. |
| `ReconcileGenerationsCommand` | `app/Console/Commands/` | Task 4: `handle()` also takes the heartbeat and records now (whole seconds) after the action. |
| `ShowReconcilerHealthController` | `app/Http/Controllers/` | Invokable. Returns JSON `{status, last_succeeded_at}` with 200 for `ok` and 503 for `stale`, and `Cache-Control: no-store`. |
| `routes/health.php` | `routes/` | `GET /health/reconciler`, named `health.reconciler`, outside every middleware group, with a comment naming D-59. |
| `StorableTextRule` | `app/Domain/Shared/Rules/` | Moved in task 6. Task 7: `:Attribute` and the docblock. Consumers: `CreateGenerationRequest`, `CreateNewUser`. |
| `CreateNewUser` | `app/Actions/Fortify/` | Task 7: the name rule. Task 8: the limit, checked before validation and hit after create (Story clarifications), through the `RateLimiter` facade on the default store (`database` in production). Constant `REGISTRATION_LIMIT_KEY_PREFIX`; docblock update. |
| `FortifyServiceProvider` | `app/Providers/` | Task 9: the 404 callback. |
| `RedirectExpiredSession` | `app/Http/Responses/` | Invokable `(Response, Throwable, Request): Response`.<br>- Constants `NOTICE` ("Your session expired. Please try again.") and `FLASH_KEY` (`notice`).<br>- Handles the 419 and the guest-on-unsafe-method cases (Story clarifications) and returns any other response unchanged.<br>Consumer: `bootstrap/app.php`. |
| `CheckGenerationMoneyAction` | `app/Domain/Billing/Actions/` | `execute(Generation): list<string>`: the problems found, empty when every check holds. It reuses `EstimateGenerationCostAction`, `CalculateProviderCostAction` (with `TokenUsage` and `TokenRates::from` on the row's own `rates`) and `CalculateUserChargeAction`.<br>- Every row: one hold of −`user_estimate_max`, which equals the recomputed estimate's user max.<br>- Non-terminal: one problem, "not terminal yet".<br>- Succeeded: provider cost equals Σ usage × its own rates; the charge equals ceil(provider × 125 / 100) and is at most the hold; the ledger is exactly hold, release of +max, and charge; image and output tokens equal the estimate; text and reasoning tokens are within its bounds.<br>- Failed: hold and release only, a zero or null charge, and a provider cost only when Pixelforge billed it (D-09), equal to its usage × rates.<br>Consumer: the command. |
| `CheckGenerationMoneyCommand` | `app/Console/Commands/` | `app:check-generation-money {--since=}`, defaulting to 24 h ago, read-only. One table row per generation created since then, oldest first:<br>- ULID, the prompt's `[keyword]` tokens only (no prompt text), size · quality · refs, status, failure code (local reason else Pixelforge's code), provider n$, charge n$;<br>- Check: `OK`, or the problems joined by "; ".<br>A totals line follows (Σ provider, Σ charge). It exits 1 when any row has a problem or none matched. |

## Frontend
| File | Role |
| --- | --- |
| `components/ui/Notice.vue` | Presentational, with a `message` prop and no Inertia imports. It renders `<p role="status">` styled like `SubmitGenerationArea`'s unavailable note (zinc box, `text-sm`), and wraps at 375 px. |
| `layouts/AuthLayout.vue` | Renders `<Notice>` inside the card, between the heading and the form, while `usePage().flash.notice` is set. |
| `layouts/AppLayout.vue` | The same, at the top of `<main>`. |
| `types/inertia.d.ts` | `flashDataType.notice?: string`, documented as the server's one-off notice (D-60). |

The Register page needs no change: the limit is an `email` field error.

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-57 | Public registration stays open on production. Each client IP (`request()->ip()`, D-21) may create 3 accounts an hour (`AUTH_REGISTRATIONS_PER_HOUR`), counted when an account is created. Past that, sign-up shows an inline message under Email and creates nothing. Dev and E2E raise the limit to 1,000 through the compose anchor and `.env.example` (D-48) | Pixelforge is a fictional API built for this assessment, so its spend is not real money, and a reviewer can sign up. Spend stays bounded by the per-user create throttle (D-39), Pixelforge's 500/day cap and, from PE-017, balances. Counting created accounts, not attempts, never locks out someone fixing a typo. E2E registers a user per spec from one IP. |
| D-58 | The live smoke is the PHPUnit group `live`, excluded in `phpunit.xml` and run only by `make live-smoke`. Its guard reads the dev key from `.env` itself. It refuses before any client exists when the app or `.env` says production, when `CI` is set, or without a key. It drives `HttpPixelforgeClient` directly with the idempotency key `live-smoke-` + a UUID, and only the configured base URL gets past `preventStrayRequests` | `phpunit.xml` keeps forcing the key empty, so the default suite never sees it, and nobody edits `.env`. On the droplet `shared/.env` says production, so the file the key would come from also refuses it. A lowercase, hyphenated key can never be a ULID. The budget decorator counts in the test database, not the app's, so wrapping the client in it would prove nothing. One run sends about 40 requests in at most 3 min. |
| D-59 | `app:reconcile-generations` stores the instant of each run that completes as a forever key on the pinned database cache store (D-44). `GET /health/reconciler` sits outside every middleware group. It answers 200 `ok` while that instant is at most 5 min old, and 503 `stale` otherwise, including before the first run. It shows the instant and nothing more | Deploys run `artisan optimize`, which never clears the application cache. The database store is shared by the cron and PHP-FPM without Redis (D-20), so no migration is needed. After a `cache:clear` it reads stale for at most a minute. The status code suits any uptime check, and `/up` keeps meaning "the app boots". |
| D-60 | A 419 on any request that is not a plain JSON call becomes a 303 back to the previous page on our own host, else to Sign in, flashing `notice` "Your session expired. Please try again." A guest's POST to a signed-in route gets the same notice on its redirect to Sign in. Both layouts show the notice as a status message | Inertia shows a non-Inertia 419 in its error modal. Laravel 13 lets a same-origin browser request through without a token (`Sec-Fetch-Site`), so in a modern browser an expired session usually reaches `auth` as a guest instead of a 419, and that path needs the message too. Only our host is a redirect target, so a cross-site form can't bounce through us. |
| D-61 | `POST /user/confirm-password`, which Fortify always registers, answers 404 like its page, through `Fortify::confirmPasswordsUsing`, so no password is ever checked there | No page asks for a password confirmation, so the route was only an unthrottled password oracle for someone holding a stolen session (PE-003 audit #8). Closing it beats throttling a feature nobody uses. |

## Tests
| Test | Location | Covers |
| --- | --- | --- |
| `LiveSmokeGuardTest` | `tests/Feature/Pixelforge/Live/` | Default suite, under `Http::fake()` with `assertNothingSent()` after each row:<br>- the app's environment production → the production message;<br>- `.env` `APP_ENV=production` → the same;<br>- `CI=true` → the CI message;<br>- a missing, empty or absent key → the key message;<br>- production wins over CI and a key;<br>- a local `.env` with a sentinel key and no CI → a configured client for the base URL;<br>- no message contains the sentinel;<br>- `phpunit.xml`'s `groups/exclude` lists `live` and nothing includes it;<br>- `LivePixelforgeSmokeTest` carries the `live` group at class level (reflection). |
| `LivePixelforgeSmokeTest` | `tests/Feature/Pixelforge/Live/` | Group `live`, real API. `setUp()` turns a refusal into `fail()`, then allows stray requests only to `pixelforge.base_url/*` and turns on `Http::record()`. One test per AC, chained with `#[Depends]` from create onward:<br>- AC 3: the pricing diff;<br>- AC 2, 4: 202, `queued`, a `gen_` id, `generation_id` echoed, the estimator's 11–15 and 17,525,000;<br>- AC 5: 202, `Idempotent-Replayed: true`, the same id, `replayed`;<br>- AC 6: `IdempotencyKeyReusedException` and 409;<br>- AC 7: `succeeded` within 3 min, polled at most every 5 s, `usage` non-null, `rates` = `config('pricing.rates')`;<br>- AC 8: text tokens 11–15, image 0, output 280, reasoning 0–500, raw `total_tokens` present and equal to the sum, provider cost ≤ the estimate's max, the cost line printed. |
| `ReconcileGenerationsCommandTest` (existing) | `tests/Feature/Console/` | Task 4: a run records now; a run where the action throws (container-bound double) records nothing, and an older instant stays. |
| `ReconcilerHealthTest` | `tests/Feature/Health/` | Never ran → 503, `stale`, null; 300 s ago → 200, `ok`, the instant in Zulu; 301 s → 503 `stale`; exactly the two keys; `no-store`; no `Set-Cookie`. |
| `RegistrationTest` (existing) | `tests/Feature/Auth/` | Task 7: invalid UTF-8 (`"A\xFFda"`) and a NUL in the name → "Name contains characters that can't be sent.", no account, the redirect back; the existing rows are unchanged. |
| `RegistrationThrottleTest` | `tests/Feature/Auth/` | Frozen time, from `198.51.100.7`, signing out between accounts (Fortify signs a new user in):<br>- 3 accounts, then the 4th is refused under `email` with "…in 60 minutes.", no 4th account, still a guest;<br>- failed validations don't count;<br>- another IP is unaffected;<br>- `X-Forwarded-For` is ignored;<br>- after 3,600 s it works again, and at 3,599 s "…in 1 minute.";<br>- the limit is configurable, and defaults to 3;<br>- the suite runs with 3 even though the container sets 1,000. |
| `LocalEnvTemplateTest` (existing) | `tests/Unit/` | Task 8: `.env.example` holds 1000, and compose's default equals it. |
| `FortifyRoutesTest` (existing) | `tests/Feature/Auth/` | Task 9: the wrong- and right-password rows become 404 for a signed-in user, with no `auth.password_confirmed_at` in the session; the guest redirect and the route list are unchanged. |
| `LoginThrottleTest`, `CreateGenerationThrottleTest` (existing) | `tests/Feature/Auth/`, `tests/Feature/Generation/` | Task 10:<br>- the 6th wrong password on the Inertia path → 302, not 429; followed, it renders `Auth/Login` with `errors.email` holding the lockout copy;<br>- the 11th generation: 10 holds in the ledger and the submit job pushed 10 times (`Queue::fake`). |
| `ExpiredSessionTest` | `tests/Feature/Auth/` | Task 11. A `PreventRequestForgery` subclass that never skips in unit tests is bound in the container. Requests send no `Sec-Fetch-Site` unless a row says so:<br>- a guest's Sign in with a bad token and Inertia headers → 303 to `/login` with the flash (`assertInertiaFlash`); followed, `Auth/Login` has it (`hasFlash`); not signed in, no failure counted;<br>- the same for Create account: no account;<br>- a signed-in Generate with a bad token → 303 to `/dashboard` with the flash; no generation, hold or job;<br>- a Referer on another host, or none → `/login`;<br>- `postJson` with a bad token keeps 419;<br>- a guest's POST to `/generations` with `Sec-Fetch-Site: same-origin` (the modern expired session) → `/login` with the flash, and the intended URL kept;<br>- a guest's GET `/dashboard` → `/login` with no flash. |
| `Notice.test.ts` | `tests/js/integration/components/ui/` | Renders the message as text in a `role="status"` paragraph. |
| `AuthLayout.test.ts`, `AppLayout.test.ts` (existing) | `tests/js/integration/layouts/` | With `givenFlash({ notice })` the status shows the copy; with `givenNoFlash()` there is no status. |
| `auth.spec.ts` (existing) | `tests/e2e/` | Task 12, desktop and mobile: register, open Generate, fill a prompt, `context.clearCookies()`, submit → URL `/login`, and the status reads "Your session expired. Please try again."; no horizontal scroll at 375 px. |
| `ProductionErrorPagesTest` | `tests/Feature/Production/` | As production with debug off, on `https://prompt.sergiupopa.dev`:<br>- an unknown path → 404 "Not Found";<br>- a test-only route throwing an exception whose message holds SQL, a file path and an env value → 500 "Server Error", also for an Inertia visit;<br>- neither body contains `Stack trace`, `vendor/`, `SQLSTATE`, `base_path()`, the message, `APP_KEY` or the DB password. |
| `ProductionEnvTemplateTest` (existing) | `tests/Unit/` | Task 14: the three names are appended to `NAMES`; the PE-015 "no Pixelforge" test becomes "exactly these three Pixelforge names, so every other setting keeps its production default"; driver `http`; key and secret blank. |
| `CheckGenerationMoneyActionTest` | `tests/Unit/Domain/Billing/Actions/` | RefreshDatabase, on the red fox (`givenTheRedFoxGeneration`, `transitionGeneration`):<br>- succeeded (5 / 0 / 280 / 312) → no problems;<br>- failed → none;<br>- `lost` and then a provider success (D-09) → none;<br>- queued → "not terminal yet";<br>- each problem by a raw-row tamper: charge off by 1 n$, provider cost off, a missing release, output 281, text tokens above the bound, a hold that differs from the max. |
| `CheckGenerationMoneyCommandTest` | `tests/Feature/Console/` | Two good rows → exit 0, a table with `OK`, the keywords, and totals; a tampered row → exit 1 with its problem; `--since` filters; none matched → exit 1; the prompt text is never printed. |

## Manual production checks (orchestrator, after merge)
The orchestrator runs these over SSH as `admin`, in `/home/admin/web/prompt.sergiupopa.dev/current` unless the item says otherwise. They run after CI is green on `main`. No value of a key or secret is ever printed, echoed or put on a command line: secret presence is a boolean, and log checks print counts only. Results go into a PR comment as a ticked list, with no secrets and no personal data.
1. **Dashboard (AC 9, 10).** Create a dedicated production key, never the dev key. Set its webhook URL to exactly `https://prompt.sergiupopa.dev/webhooks/pixelforge` (no trailing slash), and keep its `whsec_…` secret.
2. **`shared/.env` (AC 9).**
    - From the domain folder: `grep -c '^PIXELFORGE_' shared/.env` → 0.
    - Locally: `read -rs` the key and the secret, pipe a `printf` of the three lines (`PIXELFORGE_DRIVER=http`, the key, the secret) into `ssh admin@164.90.236.77 'cat >> /home/admin/web/prompt.sergiupopa.dev/shared/.env'`, then `unset` both.
    - Then `grep -c '^PIXELFORGE_' shared/.env` → 3, and `stat -c '%a %U:%G' shared/.env` → `600 admin:admin`.
3. **Deploy (AC 9).** Run `dep deploy`, then `make smoke-production` (README; `make up` first). It covers AC 14, the AC 11 404, AC 18 and AC 10's no-redirect.
4. **Config (AC 9, 11, 12, 16).**
    - `php8.4 artisan tinker --execute 'var_dump(config("pixelforge.driver"), filled(config("pixelforge.api_key")), filled(config("pixelforge.webhook_secret")));'` → `"http"`, true, true.
    - `php8.4 artisan about --only=environment` → production, debug OFF.
    - A tinker `echo` of `pixelforge.connect_timeout_seconds`, `pixelforge.request_timeout_seconds`, `pixelforge.submit.job_timeout_seconds`, `queue.connections.database.retry_after` and `pixelforge.submit.window_seconds` → 5, 30, 60, 90 and 1800. Never `config:show pixelforge`.
    - `systemctl cat prompt-edit-worker` → its `ExecStart` equals the README's unit.
5. **Webhook (AC 10).**
    - `curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' -X POST https://prompt.sergiupopa.dev/webhooks/pixelforge` → `400` with an empty redirect.
    - Dashboard "Send test event" → the delivery log shows a 2xx within 10 s.
    - `php8.4 artisan tinker --execute 'echo DB::table("pixelforge_webhook_events")->where("type", "ping")->count();'` → at least 1.
6. **Worker and scheduler (AC 17, 18).**
    - `systemctl is-active prompt-edit-worker` → `active`.
    - `curl -s -w ' %{http_code}\n' https://prompt.sergiupopa.dev/health/reconciler` → `ok` and 200, with `last_succeeded_at` under 2 min old.
    - As root, the last `CRON … (admin) CMD (cd /home/admin/web/prompt.sergiupopa.dev…` line in `/var/log/syslog` is within the last minute.
7. **Real generations (AC 22, 23).**
    - Note `date -u +%FT%TZ`, then sign in as the reviewer, so the reviewer finds them on History.
    - Submit these on Generate:
      - `[succeed] a lighthouse at dawn`: 1024x1024, low, 0 refs;
      - `[succeed] a red fox in fresh snow`: 1536x1024, medium, 2 `https://` refs such as `https://example.com/ref-1.png` and `https://example.com/ref-2.png` (Pixelforge never downloads them);
      - `[succeed] a mountain lake at noon`: 1024x1536, high, 0 refs;
      - `[fail] a broken pocket watch`, `[blocked] a forbidden scene`, `[overloaded] a busy harbour`, `[succeed] [duplicate] two moons` and `[succeed] [slow] a slow sunrise`: each 1024x1024, low, 0 refs.
    - Every prompt is at most 40 code points.
    - Watch three of them: `[slow]` shows Processing for 60–90 s, then Succeeded without a reload. `[overloaded]` fails `provider_overloaded` after about 2 min, "Not charged". The dashboard shows two deliveries per event for `[duplicate]`.
    - Then `php8.4 artisan app:check-generation-money --since=<noted instant>` → 8 rows, every check `OK`, exit 0, totals at most 516,950,000 n$ provider and 646,187,500 n$ charge.
    - Record the `[fail]` row's error code for requirements §6.
8. **Resend (AC 10).** Note `DB::table("credit_ledger_entries")->count()`. In the dashboard, resend one delivered `generation.succeeded` event. The delivery log shows a 2xx, the count is unchanged, and the money check still passes.
9. **Logs (AC 13).** From the domain folder, each of these prints `:0` for every file:
    - `grep -c -F -f <(sed -n 's/^PIXELFORGE_API_KEY=//p' shared/.env) shared/storage/logs/laravel-*.log`;
    - the same with `PIXELFORGE_WEBHOOK_SECRET`;
    - `grep -c -E 'whsec_|Authorization|Bearer ' shared/storage/logs/laravel-*.log`.

    As root, `journalctl -u prompt-edit-worker --since today` piped into the same three counts → 0.
10. **Failed jobs (AC 19).** `php8.4 artisan queue:failed` lists them; expect none, or explain each one.
11. **Client IP (AC 20).** Repeat the README's PE-004 "Client IP" check: the throttle key ends in your public IP, which is the same `request()->ip()` the account limit uses. No account is created for this.
12. **`zend.exception_ignore_args` (PE-009 audit #8).** Read-only, as root:
    - `grep -rhsE 'zend\.exception_ignore_args' /etc/php/8.4/fpm/php.ini /etc/php/8.4/fpm/conf.d /etc/php/8.4/fpm/pool.d` → `On`;
    - `php8.4 -r 'var_dump(ini_get("zend.exception_ignore_args"));'` → `"1"`.

    If either is Off, report it and ask the user before changing anything as root.
13. **Final smoke (E2E).** After the last deploy, run `make smoke-production` again.

## README
- **Reviewer guide (task 19, AC 24), near the top:**
  - the live URL;
  - signing in: the reviewer account is shared privately and never in the repo, or create an account (3 an hour per network);
  - the test keywords (§2.7) and what each shows;
  - the money model in four bullets: integer n$, +25 % rounded up, hold the max then settle the actual, failed is free;
  - a pointer to the decisions log;
  - how to run each layer, including `make live-smoke`: the dev key from `.env`, never the production key (it would add to production's `/v1/usage` and break PE-018), and refused in production, in CI and without a key.
- **Operations (task 20, AC 25), a per-environment runbook with variable names only:**
  - **local with the fake:** `make setup` fills the webhook secret, and the dashboard needs nothing;
  - **local against the real API:** a dev key, `PIXELFORGE_DRIVER=http`, a tunnel, and the dev key's URL and `whsec_…` in `.env` (link PE-008's section); why the dev key is never the production key (`/v1/usage` and the webhook URL are per key);
  - **production:** steps 1–5 of the manual checks, in that order;
  - **changing the secret:** replace the line over stdin, never argv, and `dep deploy` at once. Deliveries signed with the old secret get 400 in the gap and are retried for up to 27 min 40 s, and the reconciler catches any that give up;
  - **how to check it works:** the delivery log, `queue:failed`, `/health/reconciler`;
  - **go-live checks:** the health check, the log grep, `zend.exception_ignore_args`, the account limit;
  - **deploys:** a deploy's FPM reload or a short outage can miss the 10 s webhook window, and Pixelforge's retries and the reconciler cover it.
- **Elsewhere:** the Status row says real Pixelforge is live, and "Pixelforge on production" is rewritten.

## Size
The estimate is ≈ 2,200 changed lines, about 55 % of them tests and docs. That is far above the ≈ 400 budget and the story's own ~300, which counted neither the money check nor the hardening tests. It ships as one PR of self-contained commits, by the project rule.

| Tasks | Content | ≈ Lines |
| --- | --- | --- |
| 1–3 | Live smoke, its guard and the Makefile target | 420 |
| 4–5 | Heartbeat and health check | 230 |
| 6–10 | Names, the account limit, password confirmation, throttle pins | 330 |
| 11–13 | 419 and expired-session notice, error pages | 420 |
| 14–15 | Env template, Docker stage | 60 |
| 16–18 | Money check, smoke rows | 450 |
| 19–21 | README and decisions | 300 |

**Cut point: after task 13.** PR A (≈ 1,400 lines) is AC 1–8, 11 (local), 15, 18, 20, 21 and the deferred audit items. PR B (≈ 800 lines) is the env template, the Docker stage, the money check, the smoke rows, the README and D-57 to D-61. Both must be merged before the manual production checks.

## Risks
- **Fortify's singleton creator.** `CreateNewUser` is resolved once per app. A constructor-injected `Request` or `#[Config]` would freeze the first request's IP and limit, so both are read at call time.
- **The live smoke and production share the account limits.** Never run it while the AC 22 generations are in progress. A `429` past its 3 retries fails the run; wait a minute and run it once more.
- **Secrets in output.** Never `dd`, `dump` or assert on a recorded request, the guard's input array or the client. `HttpPixelforgeClient`'s key is a `SensitiveParameter`, and the guard's messages name variables, never values.
- **The CSRF skip in tests.** `PreventRequestForgery` skips in unit tests. Only `ExpiredSessionTest` binds the never-skipping subclass, so no other test changes.
- **Static trusted hosts.** `ProductionErrorPagesTest` resets `Request::setTrustedHosts([])` in `tearDown()`, or later tests inherit production's host rule.
- **The heartbeat needs the database.** The pinned store is `database`, so every heartbeat test uses RefreshDatabase and commits no cache rows.
- **E2E registrations accumulate.** Dev's cache is the database, so an hour of repeated `make e2e` runs adds up. The dev and E2E value is 1,000, not a few dozen. After task 8, `docker compose up -d` is needed, or the app container keeps the old environment.
- **Sec-Fetch-Site in E2E.** Chromium sends `same-origin` to `http://127.0.0.1:8080`, so the E2E row takes the guest path, not the 419. Both land on Sign in with the notice, and `ExpiredSessionTest` covers the 419 itself.
