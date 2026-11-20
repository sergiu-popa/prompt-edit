# Story: PE-015-production-go-live

## Title
Go live with real Pixelforge: live smoke, production webhooks, hardening and first real generations

## Status
`done`

## Description
Every required feature now works against the fake driver. The walking skeleton has been deployed continuously since PE-004, with Pixelforge left unconfigured. This story turns the real integration on and ships the required scope publicly before any stretch work starts (D-19).

The work happens in this order:
1. An opt-in live smoke test proves our HTTP driver, the pinned pricing and the estimator's assumptions against the real API, using a **dev** key (D-12). The assumptions include counting prompt characters as code points.
2. Production gets its own Pixelforge key, webhook URL and signing secret.
3. The deployment is hardened against a checklist, and a reconciler health signal is added. The checklist includes the public-registration decision and session-expiry handling that PE-003 and PE-004 deferred here.
4. A few real generations covering the test keywords are created. Each is checked for exact cost and a single charge. They stay on the public app as the brief's "few generations left on it".
5. The README gets a reviewer guide and a per-environment runbook for the Pixelforge key, webhook URL and secret.

Serves:
- grading #1 correct money, proven on real data;
- grading #2 robustness, with real webhooks, duplicates, overloads and retries;
- grading #3 product sense, with an app ready for a reviewer;
- the required public deployment.

## Acceptance Criteria

**Live smoke (opt-in, dev key only)**
1. The live smoke is a PHPUnit group `live`.
    - `make test` and CI exclude it.
    - It runs only through a dedicated Makefile target.
    - It refuses to run, fails with a clear message and sends no request when `APP_ENV=production`, when the `CI` environment variable is set, or when no key is configured.
2. Each run uses a fresh idempotency key that is unique to the run and can be told apart from any generation ULID, for example by a fixed prefix.
3. `GET /v1/pricing` equals the pinned pricing snapshot exactly. Any drift fails the run with a diff. The snapshot is:
    - rates 5.00 / 10.00 / 40.00 / 12.50 per 1M tokens;
    - output tokens 280 / 420 / 420 (low), 1,100 / 1,650 / 1,650 (medium) and 4,300 / 6,450 / 6,450 (high) for 1024x1024 / 1536x1024 / 1024x1536;
    - reasoning 0–500 (low), 200–2,000 (medium) and 1,000–8,000 (high);
    - 765 tokens per reference image.
4. The smoke creates one generation: low quality, 1024x1024, no references. Its prompt is `[succeed] live smoke ` (21 code points, including the trailing space) followed by 30 × U+1F305 🌅, which is 51 code points but 81 UTF-16 units.
    - The response is `202`, status `queued`, with a `gen_…` id and `metadata.generation_id` echoed back.
    - The estimator predicts: e = ceil(51 / 4) = 13, text bounds floor(13 × 0.85) = 11 to ceil(13 × 1.15) = 15.
    - Max provider cost: 15 × 5,000 + 280 × 40,000 + 500 × 12,500 = 75,000 + 11,200,000 + 6,250,000 = 17,525,000 n$ ($0.017525000).
5. Repeating that create with the same key and the same parameters returns `202` with `Idempotent-Replayed: true` and the same id.
6. The same key with a different prompt raises the typed `idempotency_key_reused` error (`409`).
7. The smoke polls through the retrieve operation until the generation is terminal, at most once per 5 s and for at most 3 minutes.
    - It must end `succeeded`, with non-null `usage`.
    - Its `rates` equal the pinned snapshot.
8. The real usage confirms the estimator:
    - `input_text_tokens` is within 11–15. Counting UTF-16 units would give 17–25, so this pins code-point counting.
    - `input_image_tokens` = 0.
    - `output_tokens` = 280.
    - `reasoning_tokens` is within 0–500.
    - `total_tokens` = the sum of the four.

    The provider cost, Σ usage × the generation's own rates computed through PE-001, is printed in n$ and USD and is at most 17,525,000 n$. Nothing printed contains the key or the `Authorization` header.

**Production wiring**
9. Production has a **dedicated** production API key and its signing secret, stored only in the server's `shared/.env` (`chmod 600`) as `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`, and `PIXELFORGE_DRIVER=http`. Neither value appears in the repo, in CI, in any log or in the PR.
10. In the Pixelforge dashboard, the production key's webhook URL is the public PE-008 endpoint, served over HTTPS with no redirect, including no trailing-slash or http→https redirect.
    - "Send test event" (`ping`): our endpoint answers 2xx within 10 s, and the dashboard delivery log shows it.
    - Resending an already delivered `generation.succeeded` event from the dashboard gets a 2xx and writes no new ledger rows.

**Hardening checklist** (each item is verified on production and recorded in the README's operations section)
11. `APP_ENV=production` and `APP_DEBUG=false`.
    - An unknown URL renders the generic 404 page.
    - A 500 rendered with production-equivalent settings shows the generic error page, with no stack trace, SQL, env value or file path. This is verified locally, so no debug route ships.
12. Production refuses the fake driver (the PE-010 guard).
13. Production logs contain no API key, no `whsec_` value and no `Authorization` header. Checked by searching the logs after the AC 22 generations.
14. HTTPS is enforced.
    - Plain-HTTP page requests redirect to HTTPS.
    - Generated URLs use `https`.
    - The session cookie is `Secure` and `HttpOnly`.
15. Abuse limits:
    - Fortify's sign-in throttle works in PE-003's failure-counting mode. The 6th failed sign-in within a minute for the same email and IP shows "Too many login attempts. Please try again in N seconds." inline under Email, never a 429 page.
    - Generation creation is throttled per user with PE-011's limit (default 10 per minute). The 11th submission within a minute shows PE-011's inline throttle message and creates no generation, hold or Pixelforge call.
16. On production, the HTTP client timeout < the job timeout < the database queue's `retry_after`, consistent with the PE-013 submit window and backoff. The systemd worker runs with the flags documented in the repo.
17. The queue worker and the scheduler are both running on the droplet: `systemctl status prompt-edit-worker` is active, and the scheduler cron fired within the last minute.
18. The reconciler records the time of its last successful run. A separate health check, distinct from `/up`, reports stale when that time is more than 5 min old and OK otherwise.
19. Failed jobs can be listed on production, and the README says how.
20. Public registration has a recorded decision, as a decisions-log row with the next free D-number. From now on every account can spend real Pixelforge money and share the per-account 500/day cap with the reviewer. Either:
    - registration stays open and `POST /register` is throttled per IP (default 3 per hour). The 4th attempt shows an inline message on the Create account form and creates no account. The limit is config, and `.env.example` relaxes it, because E2E registers a user per spec from one IP; or
    - registration is closed on production. `/register` returns 404 there, the Welcome and Sign in pages hide "Create account", and accounts come only from the PE-004 command.

    Whichever is chosen has a feature test. Recommended: keep it open with the throttle, so a reviewer can also sign up. Spend stays bounded by the per-user create throttle, Pixelforge's 500/day cap and, from PE-017, balances.
21. An expired session (a CSRF mismatch, 419) on any Inertia form, such as Sign in or Generate, never shows a raw error page or Inertia's error modal. The user lands back on the same page with the message "Your session expired. Please try again." A feature test verifies it.

**First real generations (production)**
22. Create the generations below on production. Check each one as follows:
    - Every succeeded row:
      - provider cost = Σ usage × its own `rates`;
      - exactly one charge = ceil(provider × 125 / 100), and its hold is released;
      - image and output tokens are exactly as estimated, and text and reasoning tokens are within the bounds.
    - Every failed or rejected row: no charge, and its hold is released.

| Prompt contains | Size · quality · refs | Expected |
|---|---|---|
| `[succeed]` | 1024x1024 · low · 0 | Succeeded, image shown, actual ≤ max |
| `[succeed]` | 1536x1024 · medium · 2 refs | Succeeded; `input_image_tokens` = 1,530 and `output_tokens` = 1,650 exactly |
| `[succeed]` | 1024x1536 · high · 0 | Succeeded; `output_tokens` = 6,450 exactly |
| `[fail]` | 1024x1024 · low · 0 | Failed, failure copy, "Not charged" |
| `[blocked]` | 1024x1024 · low · 0 | Rejected by content policy, "Not charged" |
| `[overloaded]` | 1024x1024 · low · 0 | Retrying, then failed `provider_overloaded` after the PE-013 deadline, "Not charged" |
| `[succeed] [duplicate]` | 1024x1024 · low · 0 | Succeeded; the dashboard shows two deliveries per event; one charge |
| `[succeed] [slow]` | 1024x1024 · low · 0 | Processing for 60–90 s, then Succeeded without a reload |

23. Spend is bounded. Every prompt is at most 40 code points, so e ≤ 10 and max text ≤ 12 tokens. The maximum provider costs per row are:
    - low 1024x1024: 12 × 5,000 + 280 × 40,000 + 500 × 12,500 = 17,510,000 n$;
    - medium 1536x1024 with 2 refs: 60,000 + 1,530 × 10,000 + 1,650 × 40,000 + 2,000 × 12,500 = 106,360,000 n$;
    - high 1024x1536: 60,000 + 6,450 × 40,000 + 8,000 × 12,500 = 358,060,000 n$, with a hold of ceil(358,060,000 × 125 / 100) = 447,575,000 n$ ($0.447575000).

    Only the five `[succeed]` rows can be billed. Together they cost at most 516,950,000 n$ of provider cost ($0.516950000) and at most 646,187,500 n$ of user charge ($0.646187500). The set is 8 generations, far below the 500/day account cap.

**README**
24. The README has a reviewer guide covering:
    - the live URL;
    - how to sign in (the reviewer account is shared privately and never appears in the repo);
    - the test keywords to try;
    - a summary of the money model (integer n$, +25% rounded up, hold the max then settle the actual, failed = free);
    - a pointer to the decisions log;
    - how to run each test layer, including the opt-in live smoke and its dev-key-only rule.
25. The README operations section has a per-environment runbook for the Pixelforge key, webhook URL and signing secret. It gives variable names only, never values:
    - **local with the fake driver:** `make setup` generates the local webhook secret (PE-010), and nothing is configured in the dashboard;
    - **local against real Pixelforge:** use a dev key, `PIXELFORGE_DRIVER=http`, a tunnel, and the dev key's webhook URL and `whsec_…` secret in `.env` (PE-008's section). Explain why the dev key must never be the production key: `/v1/usage` and the webhook URL are per key;
    - **production:** put the dedicated key and secret in the server's `shared/.env` and redeploy, set the exact final https webhook URL in the dashboard (no redirect, no trailing slash), then press "Send test event";
    - **changing the secret**, if it is ever regenerated in the dashboard: update `shared/.env` and redeploy straight away. Deliveries signed with the old secret get 400 in the gap and are retried by Pixelforge for up to 27 min 40 s. The PE-014 reconciler catches any that give up;
    - **how to check it works:** the dashboard delivery log, the failed-jobs listing (AC 19) and the reconciler health check (AC 18).

## Scope

### In Scope
- An opt-in live smoke:
  - PHPUnit group `live`, excluded by default and from CI, run through a Makefile target;
  - guards for production, CI and a missing key;
  - a dev key only, and a unique idempotency key per run.
- Live smoke checks:
  - pricing equals the snapshot;
  - a create with a code-point-heavy `[succeed]` prompt returns 202;
  - a replay returns `Idempotent-Replayed: true`;
  - a reused key with different parameters returns 409;
  - polling until terminal;
  - usage within the estimator's bounds;
  - cost computed from the generation's own usage × rates.
- Production Pixelforge wiring: a dedicated key and secret in the server's `shared/.env`, `PIXELFORGE_DRIVER=http`, the webhook URL in the dashboard, a verified test ping and a resend.
- The hardening checklist, verified and documented:
  - the reconciler health signal;
  - the public-registration decision with its throttle or closure;
  - friendly session-expiry (419) handling.
- Real generations on production, checked and left in place.
- The README reviewer guide, and an operations section with the per-environment runbook.

### Out of Scope
- Stretch features: operator report (PE-016), balances (PE-017) and `/v1/usage` reconciliation (PE-018).
- Refreshing the demo generations before hand-in (PE-018).
- Custom domains, monitoring vendors and alerting.
- Secret rotation tooling. Pixelforge documents no rotation; the runbook only covers a manual change.
- New product behaviour. Bugs found here are fixed in this story only if they are small. Otherwise they become their own story.

## Technical Notes
- **Depends on:**
  - PE-004-walking-skeleton-own-server: the droplet topology of web, the systemd worker, the cron scheduler and Postgres 17, with database queue, cache and sessions.
  - PE-014-reconciliation-poller: the last required robustness piece.
  - Every required story up to PE-014 is merged first.
- **Decisions:**
  - D-12: the fake driver for local and E2E, plus an opt-in live smoke against the real API. Shared with PE-010.
  - D-19: the user's own droplet with Deployer, with the required scope shipped before stretch. Shared with PE-004.
  - A new decisions-log row for registration (AC 20).
- **Project rule exception:** CLAUDE.md says tests never call the real Pixelforge API. The `live` group is the single exception D-12 sanctions. It never runs in `make test`, `make check` or CI, and a default-suite test proves the exclusion.
- **Deferred items arrive here:**
  - PE-003 deferred registration rate limiting and the friendly 419 page.
  - PE-004 deferred whether registration stays open once real spend is possible.
  - Inertia shows a non-Inertia 419 response in its error modal, so the 419 must come back as a redirect with a flash message.
  - From the PE-003 audit: `POST /user/confirm-password`, which Fortify always registers, is not throttled. Throttle it, or prove it is unreachable.
  - From the PE-003 audit: a crafted `POST /register` with an invalid-UTF-8 name answers 500 (Postgres error 22021). Reject invalid UTF-8 in validation with a field error.
- **Pixelforge facts** (https://pixelforge.promptedit.dev/docs#authentication, #pricing, #webhooks, #usage, #testing, #images):
  - The webhook URL, the signing secret and usage are **per key**.
  - Rate limits (60 rpm, 3 concurrent, 500/day) are **per account**, so the smoke and production share them.
  - Webhook URLs must be public, and redirects are not followed.
  - A webhook counts as delivered on any 2xx within 10 s. Otherwise it is retried after 10 s, 30 s, 2 m, 10 m and 15 m.
  - The dashboard can resend any event and shows every delivery attempt.
  - `[overloaded]` returns 503 at submit, and nothing is created or billed.
  - Image URLs stop working 24 h after completion, and refetching does not extend them. That is acceptable here, because PE-018 refreshes the demo generations before hand-in.
- **The live smoke must never use the production key.** That would add to production's `/v1/usage` and break PE-018's exact match. The production key exists only in the server's `shared/.env`, and the local `.env` holds the dev key as `PIXELFORGE_API_KEY`. A smoke run costs at most 17,525,000 n$ of provider cost. The replay and the 409 cost nothing.
- A deploy (the PHP-FPM reload) or a short outage of the droplet can miss the 10 s webhook window. Pixelforge's retries and the PE-014 poller cover that. Note it in the operations section.
- Never print, log or commit credentials, including in test output, README examples or PR descriptions. Env var names are fine.
- **Required test layers:**
  - **PHP feature, group `live`** (opt-in, real API, dev key): AC 3–8.
  - **PHP feature, default suite:**
    - the `live` group is excluded;
    - the smoke guard refuses in production, in CI and without a key, and sends nothing (`Http::fake` asserts no request);
    - the reconciler records its last successful run, and the health check reports stale after 5 min and OK before;
    - the registration choice: either the per-IP throttle (the 4th attempt is refused inline, no account) or 404 on production;
    - the 419 redirect with its message;
    - the sign-in lockout appears inline, not as a 429.
  - **Existing suites** (PHPUnit, Vitest, Playwright against the fake) stay green.
  - **Manual production checks** (AC 9–23) are recorded in the PR description as a ticked checklist, with no secrets and no personal data.
  - **E2E:** re-run the PE-004 production smoke (sign-in and the app shell load) after the final deploy.
- **PR size:** about 300 changed lines (smoke test, guard, health signal, registration throttle or closure, 419 handling, Makefile target, phpunit group config, README). Most of the story is operational verification.

## Mockups / References
No UI beyond two messages: the 419 message ("Your session expired. Please try again.") and, if registration stays open, its throttle message. The pages are the existing Generate and History pages, now backed by real Pixelforge. The README gains a reviewer guide and an operations section.

- https://pixelforge.promptedit.dev/docs#authentication
- https://pixelforge.promptedit.dev/docs#pricing
- https://pixelforge.promptedit.dev/docs#idempotency
- https://pixelforge.promptedit.dev/docs#retrieve-generation
- https://pixelforge.promptedit.dev/docs#webhooks
- https://pixelforge.promptedit.dev/docs#testing
- https://pixelforge.promptedit.dev/docs#images
- requirements.md §2, §3 and §5 (D-12, D-19)
- PE-004's deployment runbook in the README (`shared/.env`, the systemd worker, the scheduler cron)
