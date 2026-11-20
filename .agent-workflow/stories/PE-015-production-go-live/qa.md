# QA — PE-015-production-go-live

**Verdict: QA PASSED** (for the local scope; production-only checks are open, see *Open items*)

Run: 2026-11-20, branch `pe-015-production-go-live` at `47503e9`. Environment: Docker stack up (app, worker restarted, scheduler running), fake driver for E2E and browser, dev key for the live smoke.

## Suite

| Check | Command | Result |
|---|---|---|
| Lint, stan, PHP tests, ESLint, Prettier, vue-tsc, Vitest | `make check` | exit 0. PHPUnit 1729 passed (37,027 assertions); Vitest 503 passed; Pint and Larastan clean |
| Full Playwright E2E (fake driver, desktop + mobile) | `make e2e` | 44 passed, 2 skipped (intentional touch-only and mouse-only tests) |
| Opt-in live smoke | `make live-smoke` | 6 passed (58 assertions) |
| Guard: CI set | `CI=true make live-smoke` | exit 2, "The live smoke never runs in CI (the CI variable is set). Run `make live-smoke` locally." Nothing sent |
| Guard: default suite excludes `live` | `php artisan test --filter=LivePixelforgeSmokeTest` | "No tests found" (phpunit.xml excludes group `live`) |

## Acceptance criteria

| AC | Evidence | Status |
|---|---|---|
| 1 live group, excluded from `make test`/CI, own target, refuses on production / CI / no key | phpunit.xml exclude; `LiveSmokeGuardTest` refusal cases (`Http::assertNothingSent`); CLI CI refusal above. No-key refusal has no CLI run because it needs an edited `.env`, which this pass does not touch | Pass (no-key via unit test only) |
| 2 fresh idempotency key per run | `test_a_create_is_accepted_and_queued` | Pass |
| 3 `GET /v1/pricing` equals snapshot | `test_the_live_pricing_equals_the_pinned_snapshot` | Pass |
| 4 create 202 `queued`, prompt 51 code points | `test_a_create_is_accepted_and_queued` | Pass |
| 5 replay `Idempotent-Replayed: true` | `test_the_same_key_and_parameters_replay_the_generation` | Pass |
| 6 reused key, other prompt → 409 | `test_the_same_key_with_another_prompt_is_refused` | Pass |
| 7 poll to terminal, ≤ 3 min, `succeeded` | `test_the_generation_succeeds_within_three_minutes` | Pass |
| 8 usage confirms estimator; cost ≤ 17,525,000 n$ | `test_the_usage_confirms_the_estimate` | Pass (see Live smoke) |
| 9 dedicated production key in `shared/.env` | not checkable here (no SSH, no server access in this pass) | Open (production) |
| 10 production webhook URL, ping, resend | not checkable here | Open (production) |
| 11 `APP_ENV=production`, generic 404/500 | production-equivalent error page test passes in the default suite; live production values not checked | Partial (production values open) |
| 12 production refuses fake driver | `AppServiceProvider::refuseFakeDriverInProduction` covered by default suite | Pass (local) |
| 13 production logs free of key, `whsec_`, `Authorization` | needs production logs | Open (production) |
| 14 HTTPS enforced, cookie flags | needs production | Open (production) |
| 15 sign-in lockout inline; generation throttle inline | Browser: 6th failed sign-in shows "Too many login attempts. Please try again in 58 seconds." under Email, HTTP 302, no 429 (375/768/1440, both projects). `LoginThrottleTest` passes. Browser: 11th generation refused inline, 10 generations created (mobile 375) | Pass |
| 16 timeouts ordering, worker flags | production systemd and config | Open (production) |
| 17 worker active, scheduler cron fired | scheduler container running; worker restarted and running; droplet not checked | Open (production) |
| 18 reconciler health OK/stale | `/health/reconciler` returned `ok` at 15:58:51Z and 16:03:51Z; the scheduler log shows `app:reconcile-generations` running every few seconds in dev. Stale-after-5-min covered by the default suite | Pass (local) |
| 19 failed jobs listed, README says how | README runbook; production not checked | Open (production) |
| 20 registration decision with its throttle | `RegistrationThrottleTest::test_the_fourth_account_from_one_ip_within_an_hour_is_refused_inline` passes. Browser not run (would need a temporary config change) | Pass (feature level) |
| 21 expired session (419) → same page with notice | Browser on Sign in and Generate at 375/768/1440, both projects: notice "Your session expired. Please try again." shown, no error modal, no 419 text. `ExpiredSessionTest` passes | Pass |
| 22 real generations on production | not run in this pass (production, and out of this step's allowed actions) | Open (production) |
| 23 spend bounds | production only | Open (production) |
| 24 README reviewer guide | present; reviewed for secrets and placeholders (see below). Live URL and reviewer account sections present | Pass (content review) |
| 25 runbook per environment | present in README operations section | Pass (content review) |

## Live smoke (dev key, real Pixelforge)

- Generation `gen_01M7WTSHYNBTH1VCKRE8BVJSCN`, low, 1024x1024, prompt `[succeed] live smoke` + 30 × 🌅.
- Usage: input text 13 (estimate bounds 11–15, so code-point counting holds), image 0, output 280, reasoning 3.
- Provider cost: **11,302,500 n$ ($0.011302500)**, check `13×5,000 + 280×40,000 + 3×12,500`. Estimated max 17,525,000 n$.
- Pricing: `GET /v1/pricing` matched the pinned snapshot exactly.
- Key hygiene: the key and webhook secret from `.env` were counted with `grep -c -F` against `live-smoke.log` and both counts are 0. `Authorization`, `Bearer` and `whsec_` also count 0.

## Browser pass (fake driver, temporary probe, deleted before commit)

Widths 375, 768 and 1440, projects desktop and mobile (Pixel 7 emulation).

- **Measurements:** 48 screen captures (welcome, login, register, dashboard, history, sign-in 419, generate 419, lockout). `scrollWidth == clientWidth` on every one (for example `375/375`, `768/768`, `1440/1440`), so overflow is 0 px everywhere. Console and page errors: 0. HTTP error responses: none except the intentional 419 and 302 flows.
- **419 on Sign in:** the POST was re-sent with a cross-site marker so Laravel's token check ran. Response 303, lands on `/login`, notice shown, no "Page Expired" or 419 text.
- **419 on Generate:** the POST was re-sent the same way. Response 303, lands on `/dashboard` with the prompt kept and the notice shown.
- **Lockout:** attempts 1–5 show "These credentials do not match our records."; attempt 6 shows "Too many login attempts. Please try again in 58 seconds." under Email. No 429 at any step.
- **Generation throttle (mobile, 375):** submissions 1–10 redirected (302); submission 11 showed "You're submitting too quickly. Try again in N s." and no new generation.
- **Screenshots and measurements:** `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-015/shots/` (for example `desktop-375-generate-419.png`, `desktop-375-lockout.png`, `mobile-768-login.png`; JSON per screen; `*-lockout-attempts.txt`, `*-signin-419-status.txt`). Not committed.

Note: Chromium sends `Sec-Fetch-Site: same-origin`, and Laravel's `PreventRequestForgery` skips the token check for that. A tampered XSRF cookie therefore never reaches the 419 path from a real browser. The existing E2E test covers the guest path, and this pass reproduced the token-mismatch path by re-sending the POST with a cross-site marker. The app's behaviour in both cases is correct.

## Health check

`GET /health/reconciler` → `{"status":"ok","last_succeeded_at":"2026-11-20T15:58:51Z"}` (HTTP 200), and later `…16:03:51Z`. The timestamp moved, so the dev scheduler is running the reconciler.

## Docs review

- README reviewer guide and operations runbook read against the runs above. Secret scan: 0 matches for the dev key and webhook secret in README and `.agent-workflow`, and 0 `whsec_…` literals. Reviewer password: absent (only the shell pattern `read -rs`).
- Reviewer email in the README is the placeholder `reviewer@example.com`.

## Data created in this pass (dev DB, since 15:55Z)

- 67 e2e users (accounts created by the E2E suite and the browser probe; not deleted, since the suite creates them by design).
- 46 generations: 40 succeeded, 6 failed (all fake-driver e2e and probe runs). Generations are append-only, so none were removed.
- The throttle probe user holds exactly 10 generations.
- 1 generation on the dev Pixelforge account (live smoke, 11,302,500 n$ provider cost).
- Production: none created.

## Open items (not verified in this pass)

These are production or post-deploy checks. QA was not allowed to SSH, run `dep`, run `make smoke-production`, or touch `.env`.

- AC 9, 10, 13, 14, 16, 17, 19 and 22 (production wiring, logs, HTTPS, systemd, failed-jobs listing, production generations).
- AC 11 (production values for the error pages) and AC 23 (spend bounds on production).
- AC 20 browser check at the throttle (feature test passes; not driven in browser).

These belong in the PR's manual checklist (story: *Manual production checks*). The story has no ticked production checklist in the repo yet.

## Regressions

None found. Full suite and E2E green.
