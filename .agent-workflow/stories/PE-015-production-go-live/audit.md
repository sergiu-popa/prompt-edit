# Audit — PE-015-production-go-live

**Verdict: PASS**

Run on branch `pe-015-production-go-live` from `2d0719e` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (round 4 APPROVE) and `qa.md` (QA PASSED, local scope) present.

## Scope

I read the whole `[PE-015]` diff (`origin/main...2d0719e`, 54 files) against `story.md` AC 1–25, `plan.md`, all four review rounds, QA and D-57 to D-61. That covers:
- `LiveSmokeGuard`, `LivePixelforgeSmokeTest`, `LiveSmokeGuardTest`, the `live-smoke` Makefile target and the `phpunit.xml` group exclude;
- `ReconcilerHeartbeat`, `ShowReconcilerHealthController`, `routes/health.php`, `ReconcileGenerationsCommand` and their tests;
- `CreateNewUser`, `config/auth.php`, `StorableTextRule`, `FortifyServiceProvider`, `RedirectExpiredSession`, `bootstrap/app.php` and their tests;
- `CheckGenerationMoneyAction`, `CheckGenerationMoneyCommand` and their tests;
- `Notice.vue`, both layouts, `types/inertia.d.ts`, the Vitest rows, `auth.spec.ts` and `tests/smoke/production.spec.ts`;
- `.env.example`, `.env.production.example`, `docker-compose.yml`, `.docker/Dockerfile`, the README reviewer guide and runbook, and the D-57 to D-61 rows.

I also read `HttpPixelforgeClient`, `VerifyPixelforgeWebhookSignature`, `SettleGenerationCreditsAction`, `CalculateUserChargeAction`, the ledger migration, `routes/console.php`, `deploy.php`, `.github/workflows/ci.yml`, requirements §2 and Laravel's `Http\Client\Factory::allowStrayRequests`.

Where the auditor checklist mentions Livewire, the Inertia/Vue rules in `.agent-workflow/CLAUDE.md` apply instead. PR size is not a finding.

## What was verified

- **Live smoke isolation (AC 1, 2, D-58).**
  - `phpunit.xml` excludes the `live` group and includes none. `LiveSmokeGuardTest` pins that, and pins the class-level `#[Group('live')]`, so `make test`, `make check` and CI never run it. CI's `ci.yml` builds `--target ci` and runs the default suite.
  - The guard refuses before any client exists: production (the app's or `.env`'s `APP_ENV`), then a set `CI`, then a missing key. Every refusal row runs under `Http::fake()` with `assertNothingSent()`. `make live-smoke` forwards the host's `CI` with `-e CI`. I re-ran `CI=true make live-smoke`: 2 failed with the CI message, 4 skipped, nothing sent.
  - **How the key reaches it.** `phpunit.xml` forces `PIXELFORGE_API_KEY` empty, so the configured key is never used. The guard parses the local `.env` itself. On the droplet that file is `shared/.env`, which says `APP_ENV=production`, so the only file holding the production key is refused. The smoke cannot use the production key unless someone pastes it into a local `.env`, which the README and the guard's message forbid.
  - Only `pixelforge.base_url/*` gets past `preventStrayRequests`, and the client never follows a redirect.
  - The idempotency key is `live-smoke-` + a UUID per run. Lowercase letters and hyphens are outside Crockford base32, and the test asserts it doesn't match the ULID pattern.
  - **No output holds the key.** Failure messages, refusals and the STDERR line name variables, never values. No recorded request, guard input or client is dumped or compared, and the key is a `SensitiveParameter`. After my run, `grep -c -F -f` with the dev key and the webhook secret over the full output gave 0 each. `Authorization`, `Bearer` and `whsec_` also gave 0.
- **Exactness (AC 3, 4, 7, 8).**
  - The pricing diff compares key-sorted copies with `assertSame`, so a drift fails with PHPUnit's array diff, and types count.
  - The 11–15 bounds and the 17,525,000 n$ max come from `EstimateGenerationCostAction` on a prompt pinned at 51 code points and 81 UTF-16 units.
  - The cost goes through `CalculateProviderCostAction` with `TokenRates::from` on the generation's own rates, in integer n$.
  - My run: input text 12, image 0, output 280, reasoning 433. That is 12 × 5,000 + 280 × 40,000 + 433 × 12,500 = 16,672,500 n$, at most 17,525,000.
- **Registration throttle (AC 20, D-57).**
  - The key is `registrations:` + `request()->ip()`, read at call time because Fortify's creator is a singleton. There is no `trustProxies` anywhere in `app/`, `bootstrap/` or `config/`, so production gets the mod_remoteip client address (D-21), and `X-Forwarded-For` is ignored (tested).
  - The 4th account is refused under `email` before validation, and no account is created (tested). The config default is 3. The value 1,000 lives only in the compose `x-dev-bounds` anchor and `.env.example`. `AUTH_REGISTRATIONS_PER_HOUR` is cleared in `<env force>` and `<server>` in the same commit that reads it (`8cedb13`).
- **419 and expired sessions (AC 21, D-60).**
  - Any 419 on a request that isn't a plain JSON call becomes a 303 to a Referer on our own host, else to Sign in, with the flash. A guest's unsafe request that `auth` redirects gets the same flash. Both are tested with a CSRF check that really runs.
  - The webhook route is outside the web group, and its 400, 500 and 2xx are responses, not exceptions, so the callback never touches them. I added a test that proves this under the real CSRF check (#3). `/health/reconciler` is a plain JSON GET outside every group.
  - I probed the dev app with a tokenless Inertia `POST /login` and got `303` to `/login`.
- **Health check (AC 18, D-59).**
  - It is distinct from `/up`. It answers 200 `ok` at ≤ 300 s and 503 `stale` above 300 s or before any run.
  - The heartbeat is recorded only after `ReconcileGenerationsAction::execute()` returns.
  - It exposes `status` and `last_succeeded_at` only, with `no-store` and no cookie.
  - It is stored forever on the pinned `database` cache store, so a deploy, which runs `optimize` and never `cache:clear`, keeps it.
  - On production it is read with one `curl` that changes nothing.
- **Deferred fixes.**
  - `POST /user/confirm-password` answers 404 for a wrong and a right password and confirms nothing (`FortifyRoutesTest`).
  - An invalid-UTF-8 or NUL name is a field error, not a 500 (`RegistrationTest`).
  - The Dockerfile `production` stage and `npm.conf` are gone. `ci` still copies from `production-frontend`, and CI builds `--target ci`.
  - `zend.exception_ignore_args` is on the manual checklist (plan step 12, README "No arguments in traces").
- **`app:check-generation-money` (AC 22).**
  - It is read-only: one select of generations and one of each row's ledger entries.
  - Provider cost is recomputed as Σ usage × the row's own rates. The charge is `CalculateUserChargeAction`, which uses BigInteger ceiling of × 125 / 100.
  - A succeeded row's ledger must be exactly hold −max, release +max and charge −charge, in id order, which matches `SettleGenerationCreditsAction`'s write order. A failed row must be exactly hold and release, with a zero or null charge.
  - Image and output tokens must equal the estimate. Text and reasoning tokens must fall within its bounds.
  - It prints `[keyword]` tokens, never the prompt.
- **README.**
  - It holds no secret value and no reviewer password. The reviewer email in it is the placeholder `reviewer@example.com`.
  - Every command that carries a secret sends it over stdin.
  - The runbook matches `deploy.php`: shared `.env` and `storage/`, `artisan:reload` restarting the worker, the FPM reload, and daily logs at `shared/storage/logs/laravel-*.log` (`LOG_CHANNEL=daily`). It matches `.env.production.example`: three Pixelforge names, secrets blank, a `sed` to remove them before appending. It matches requirements §2: webhook URL and secret per key, no redirects followed, retries at 10 s, 30 s, 2 min, 10 min and 15 min, which add up to 27 min 40 s.
  - The config keys and the `pixelforge_webhook_events.type` column used in its commands exist.
- **Production `.env`.** Production's `shared/.env` needs exactly the three Pixelforge lines and nothing else new: `PIXELFORGE_DRIVER=http`, `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`. The only other new setting, `AUTH_REGISTRATIONS_PER_HOUR`, defaults to production's 3. `ProductionEnvTemplateTest` pins exactly these three names.
- **No secrets in logs.** No new code logs anything. The webhook middleware's existing log lines carry the reason and a truncated event id only.
- **Vue layer.** There is no `any`. `Notice.vue` is a presentational `ui/` primitive with no Inertia import. The layouts read `page.flash.notice` through a `computed`, and no page changed.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | The AC 7 rates check compared unsorted arrays, so a reordered `rates` object would fail with no drift (review S1). | `tests/Feature/Pixelforge/Live/LivePixelforgeSmokeTest.php:204` | Suggestion | Fixed | Both sides go through `sortedByKey()`, with a drift message (`3e99108`). Verified with one `make live-smoke` run: 6 passed. |
| 2 | The default limit of 3 was written twice, in `config/auth.php` and as a fallback in `CreateNewUser` (review S2). | `app/Actions/Fortify/CreateNewUser.php:80` | Suggestion | Fixed | `config()->integer('auth.registrations_per_hour')`. The config's `filter_var` default is the only 3 (`d7ab6f5`). |
| 3 | No test showed that, with CSRF really checked, the webhook route keeps its own answer and never gets the 419 redirect (auditor note: the webhook stays CSRF-exempt with its own error answers). | `tests/Feature/Auth/ExpiredSessionTest.php` | Suggestion | Fixed | New `test_the_webhook_route_keeps_its_own_answer_without_a_token`: an unsigned, tokenless POST gets 400, no `Location` and no cookie (`9f0c205`). |
| 4 | The manual production checklist had no exact command for AC 15 (sign-in and generation limits), AC 20 (the account limit's production value) or AC 21 (the expired-session redirect). These are hardening items that must be verified on production. | `README.md` (Go-live checks), `plan.md` (Manual production checks) | Warning | Fixed | The README go-live table now has a read-only `tinker` echo (`10 3`), a six-wrong-passwords browser check and a tokenless `curl` → `303 …/login` (`3fdc3bb`). The plan gets the same as manual step 13; the 11th generation and 4th account are left to their feature tests, because repeating them would create billable generations or accounts. |
| 5 | Right after the first go-live deploy the heartbeat doesn't exist yet, so `make smoke-production`'s new `ok` row can fail (review S4). | `README.md` (Per-environment runbook, step 3) | Suggestion | Fixed | Step 3 and the plan's final smoke now wait until `/health/reconciler` reads `ok` (`3fdc3bb`). |
| 6 | The secret-change command wrote `shared/.env.new` with the default umask, so the new secret was briefly world-readable before `chmod 600`. | `README.md` (Changing the secret) | Suggestion | Fixed | The remote command starts with `umask 077` (`3fdc3bb`). |
| 7 | A guest's Sign out from an expired tab shows "Your session expired. Please try again." (review S3). | `app/Http/Responses/RedirectExpiredSession.php:40` | Suggestion | Skipped | The message is true (the session did expire), and the user lands on Sign in, where Sign out would take them anyway. A route exception in a security-adjacent handler costs more than the wording. |
| 8 | The live smoke sends the dev key to `pixelforge.base_url`, which a local `.env` may override (`PIXELFORGE_BASE_URL`). | `tests/Feature/Pixelforge/Live/LiveSmokeGuard.php:77` | Suggestion | Skipped | It is the operator's own setting, and PE-009 audit #5 made the same call. The default is the docs' https URL. No redirect is followed, and the stray-request allowlist pins every request to that one base. |
| 9 | The health controller reads the heartbeat twice (once in `isStale()`, once for the instant), so a run that lands between the reads could pair `stale` with a fresh instant for one poll. | `app/Http/Controllers/ShowReconcilerHealthController.php:20` | Suggestion | Skipped | The reads are microseconds apart, the error is on the safe side (stale), and the next poll is correct. Not worth an API change to the heartbeat. |

No Critical issues were found. The one Warning (#4) is fixed.

## Checks after fixes

Run on `3fdc3bb`, after the last fix commit:

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `make check` | Exit 0. Pint PASS (292 files). Larastan no errors. PHPUnit 1,730 passed (37,030 assertions), 1 more than QA's 1,729. Vitest 503 passed in 33 files. |
| E2E, desktop and mobile | `make restart-worker`, then `make e2e` | Exit 0. 44 passed, 2 skipped (the existing touch-only and mouse-only rows of `generate.spec.ts`). |
| Live smoke, dev key | `make live-smoke` (once) | 6 passed (58 assertions). Provider cost 16,672,500 n$ against a 17,525,000 n$ max. 0 hits for the key, the secret, `Authorization`, `Bearer` and `whsec_` in the output. |
| Live smoke in CI | `CI=true make live-smoke` | Refused with the CI message: 2 failed, 4 skipped, nothing sent. |

`CreateNewUser` (an action) changed, so the worker was restarted before E2E.

Production-only checks (AC 9, 10, 11 on production, 12–19, 22, 23, plus the new step 13) stay with the orchestrator after merge, as `plan.md` → Manual production checks lists them.

## Commits

- `3e99108` [PE-015] Compare the live generation's rates regardless of key order
- `d7ab6f5` [PE-015] Read the account limit's default from config alone
- `9f0c205` [PE-015] Prove the webhook keeps its own answer under a real CSRF check
- `3fdc3bb` [PE-015] Complete the go-live checks for limits and expired sessions
- `[PE-015] Record audit` (this file, the plan's manual step 13 and the story status)
