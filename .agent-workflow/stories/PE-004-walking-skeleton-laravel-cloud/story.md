# Story: PE-004-walking-skeleton-laravel-cloud

## Title
Walking skeleton live on Laravel Cloud

## Status
`draft`

## Description
A public deployment with a few generations on it is a required deliverable. Deploying the signed-in shell now means every later story is redeployed and smoke-checked on the real platform instead of being found broken at the end (D-13).

This story provisions the full production topology the app will need: web, a queue worker, the scheduler, Postgres 17, and Redis-compatible Laravel Valkey for cache, sessions and queue. The worker and scheduler start out idle; later stories only add jobs and schedules.

Pixelforge deliberately stays unconfigured in production until PE-015, so continuous redeploys never put real money at risk. This serves the required public deployment, criterion #2 (robustness of the running system) and criterion #3 (reviewers can reach and use the app).

## Acceptance Criteria

**Platform**

1. The app runs on Laravel Cloud at a public HTTPS `*.laravel.cloud` URL, which is recorded in the README, and `APP_URL` is that https URL. Pushing to `main` deploys automatically.
2. The production environment has:
   - an App cluster (web);
   - a worker cluster running `queue:work` on the Redis connection, set to **stay awake** rather than sleep with the App cluster;
   - the scheduler enabled;
   - Laravel Serverless Postgres **17**, chosen explicitly because new clusters default to 18;
   - Laravel Valkey as the cache, session and queue store.

   `php artisan schedule:list` runs without error through Cloud's command runner, and the PR shows the worker process running (Cloud CLI or dashboard output).
3. **Builds:** the build command installs Composer dependencies without dev packages and with an optimised autoloader, runs `npm ci` and `npm run build`, and runs `php artisan optimize`. The deploy command is only `php artisan migrate --force`.
4. `GET /up` on production returns 200.
5. **Branch protection:** `main` only takes changes whose CI jobs (lint, frontend, stan, test, e2e) have passed, because push-to-deploy deploys every push to `main` without waiting for CI. The README records this.

**Production-safe configuration**

6. **HTTPS:** every URL the app generates behind Cloud's proxy uses `https://`. That covers asset URLs, form actions, the guest → `/login` redirect and the post-login redirect, with no mixed-content warnings. A feature test proves it: a request from a trusted proxy carrying `X-Forwarded-Proto: https` gets an `https://…/login` redirect.
7. **Cookies:** on production the session cookie is `Secure`, `HttpOnly` and `SameSite=Lax`. The `XSRF-TOKEN` cookie is `Secure` and not `HttpOnly`, because Inertia's HTTP client reads it. The production smoke asserts these flags.
8. **Errors:** production runs with `APP_ENV=production` and `APP_DEBUG=false`; `php artisan config:show app.debug` through Cloud's command runner prints false. An unknown URL returns the standard 404 page, with no stack trace, environment values or SQL; the smoke asserts this.
9. **Logs:** application logs reach Cloud's log viewer. After a run of the AC11 command, its info line ("User … created/updated", never the password) appears there.
10. **No Pixelforge configuration:** production has no `PIXELFORGE_*` variables, and every page and `/up` works without them.

**Reviewer/demo account command**

11. An artisan command creates or updates a user by email (name, email, password). It runs non-interactively, so it works through Cloud's command runner.
    - The email is lowercased, as in PE-003.
    - The password follows the registration rules: one under 8 characters is rejected with a non-zero exit code and no change.
    - Running it twice with the same email leaves exactly one user with the latest name and password, and exits 0 both times.
    - The password never appears in the command's output or in application logs.
    - It uses no factories or Faker, which are dev-only and absent from a `--no-dev` build.

**Smoke check and runbook**

12. An opt-in Playwright production smoke runs from a Makefile target that takes `E2E_BASE_URL` and the reviewer credentials from environment variables. Against production it checks:
    - `/up` returns 200;
    - the login page renders;
    - signing in with the reviewer account reaches the signed-in home, and signing out works;
    - the browser logs no console errors;
    - the AC7 cookie flags;
    - the AC8 plain 404.

    It never registers users and never writes data beyond a session. It is not part of `make e2e` or CI.
13. The README contains:
    - the live URL;
    - an environment-variable inventory (**names only**), marking which ones Cloud injects and which are set by hand, with `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` listed as "added in PE-015";
    - a deploy and rollback runbook: Cloud only switches traffic to a successful deployment; rollback means redeploying a previous deployment; migrations are forward-only and must stay compatible with the previous release;
    - the rule that every merged story is redeployed and smoke-checked;
    - the note that production needs its **own** Pixelforge key, because the webhook URL, signing secret and `/v1/usage` are per key;
    - the warning "don't submit generations on production before PE-015";
    - the scale-to-zero settings chosen for the App cluster, the worker, Postgres and Valkey, and why.
14. No secret is committed. Reviewer credentials, `APP_KEY` and Cloud tokens live only in Cloud or the operator's shell.

## Scope

### In Scope
- A Laravel Cloud application and production environment with push-to-deploy from `main`:
  - App cluster;
  - an always-awake worker cluster running `queue:work` on Valkey;
  - scheduler;
  - Serverless Postgres 17;
  - Valkey for cache, session and queue.
- Build commands (Composer without dev packages and with an optimised autoloader, `npm ci` + build, `optimize`) and the deploy command (`migrate --force`).
- Production-safe configuration: trusted proxies and HTTPS URLs, secure cookies, debug off, and a log channel Cloud collects.
- Branch protection on `main` requiring the CI jobs.
- The idempotent create/update user artisan command, with feature tests.
- The opt-in Playwright production smoke and its Makefile target, kept out of the default suite and CI.
- The README sections from AC13.

### Out of Scope
- Pixelforge key, webhook URL and secret, the fake/real driver switch, the live API smoke and the hardening checklist (PE-015). That checklist should also decide whether public registration stays open once real Pixelforge spend is possible.
- Usage reconciliation and the final demo generations (PE-018).
- Custom domain, monitoring/alerting, staging and preview environments, and CI-triggered deploy hooks.
- Managed queues: they switch `QUEUE_CONNECTION` to `cloud` and need `aws/aws-sdk-php`, a new dependency (see Technical Notes).

## Technical Notes
- **Decision:** D-13 (Laravel Cloud: web, queue worker, scheduler, Postgres). requirements §2: the webhook URL, secret and usage are per API key, and rate limits are per account.
- **Approval and process:**
  - Use the `deploying-to-cloud` skill (Cloud CLI, `-n` on every command, deploy then `deploy:monitor`).
  - Creating Cloud resources needs the user's Cloud account and may cost money. **Confirm with the user before provisioning**, and confirm every destructive command explicitly.
  - Adding `laravel/cloud-cli` as a dev dependency, and changing GitHub branch protection, both need the user's approval.
- **Cloud facts** (Cloud docs at laravel.com/cloud/docs, checked 2026-11-18):
  - **Postgres:** Serverless Postgres supports 17 and 18, and new clusters default to 18. Pick 17 to match dev and CI. It can scale to zero after 60 s – 7 days without queries, or never.
  - **Cache:** Redis by Upstash is deprecated and unavailable for new apps. Use Laravel Valkey, which injects `REDIS_HOST` / `REDIS_PASSWORD`. Valkey Flex sizes **scale to zero by default** (contents are snapshotted and restored). It backs sessions and the queue, so decide and record that setting.
  - **Queues:** managed queues set `QUEUE_CONNECTION=cloud` and need `aws/aws-sdk-php`. The stack decision is a Redis queue, so run `queue:work` on a worker cluster with the redis connection. Don't attach a managed queue unless the user approves the dependency.
  - **Queued jobs do not wake a sleeping environment** (Compute and Queues docs). A worker that sleeps with the App cluster would stall the PE-009 submit jobs, which is why AC2 requires the worker to stay awake. The local `deploying-to-cloud` skill text says the opposite; the Cloud docs win.
  - **Scheduler:** it is a toggle on the App or worker cluster. A sleeping Laravel environment wakes for scheduled tasks, so an every-minute schedule (PE-014) effectively keeps it awake. Factor that into the scale-to-zero choice.
  - **Wake time:** inbound requests wake new Flex sizes in under 500 ms, but legacy Flex sizes take 5–20 s. Pixelforge webhooks need a 2xx within 10 s (from PE-015 on), so avoid scale-to-zero on the App cluster or use a new Flex size.
  - **Build vs deploy:** put `optimize` in the build. Don't add `queue:restart`, `optimize:clear` or `storage:link` to deploy commands. The filesystem is ephemeral, and nothing here stores files.
  - **PgBouncer pooler:** the endpoint (`-pooler` host) exists. If it is used, avoid session-level Postgres features such as advisory locks and LISTEN/NOTIFY; that matters for PE-013 and PE-014.
- **Trusted proxies:** Cloud terminates TLS. Configure trusted proxies so `isSecure()` and URL generation are https. The Architect checks Cloud's recommended setting.
- **For PE-015:** Pixelforge follows no redirects and accepts only public URLs. The webhook URL must be the exact final https URL, with no http→https hop and no trailing-slash redirect.
- **Account command and smoke:**
  - Registration stays open (PE-003). The command exists so reviewers have a ready account and the production smoke has stable credentials.
  - How the password reaches the command is the Architect's call. The constraint: it is never written to application logs, and the README states where it may persist (for example Cloud's command history) and how to rotate it by re-running the command.
  - The CI `e2e` job runs everything in `tests/e2e` (`playwright.config.ts` `testDir`), so the production smoke must live in a separate project or directory that CI does not pick up.
- **Window before PE-015:** from PE-011 until PE-015, production has a submit form but no Pixelforge key, so don't submit generations there. Later stories must keep "boots without `PIXELFORGE_*`" true until PE-015 configures it.
- **Required test layers:**
  - PHP feature:
    - the create/update user command: create, idempotent update, lowercasing, password rule, password absent from output and logs;
    - the HTTPS redirect behind forwarded headers (AC6);
    - pages and `/up` responding with no `PIXELFORGE_*` configuration (AC10).
  - E2E (Playwright, opt-in, production only): the AC12 smoke suite.
  - No JS unit or integration tests: there is no new UI.
- **Depends on:** PE-003-sign-in-and-app-shell.
- **PR size:** about 200 changed lines: the command and its tests, config, the Makefile target, the smoke spec and the README. Most of the work is Cloud and GitHub settings, which are captured in the README rather than in code.

## Mockups / References
No UI. The story reuses the PE-003 pages.

- Laravel Cloud deploy guide: https://laravel.com/cloud/docs/deploy-guides/laravel.md
- Environments (build/deploy commands, variables): https://laravel.com/cloud/docs/environments.md
- Compute and scale to zero (wake times; queued jobs don't wake): https://laravel.com/cloud/docs/compute.md#scale-to-zero
- Workers (stay awake vs follow the App cluster): https://laravel.com/cloud/docs/workers.md
- Managed queues (`QUEUE_CONNECTION=cloud`, `aws/aws-sdk-php`): https://laravel.com/cloud/docs/queues.md
- Serverless Postgres (versions, pooler, scale to zero): https://laravel.com/cloud/docs/resources/databases/postgres.md
- Laravel Valkey (injected variables, scale to zero): https://laravel.com/cloud/docs/resources/caches/valkey.md
- Pixelforge webhooks (per-key public URL, no redirects, 10 s window): https://pixelforge.promptedit.dev/docs#webhooks
- Pixelforge authentication (one key per environment): https://pixelforge.promptedit.dev/docs#authentication
- requirements.md §2, §2.5; decision D-13
