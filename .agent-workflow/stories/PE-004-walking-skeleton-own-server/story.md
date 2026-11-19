# Story: PE-004-walking-skeleton-own-server

## Title
Walking skeleton live on prompt.sergiupopa.dev

## Status
`in-review`

## Description
The brief requires a public deployment with a few generations left on it. Deploying the signed-in shell now means every later story gets redeployed and smoke-checked on the real server, instead of being found broken at the end.

The app runs on the user's own HestiaCP droplet at **https://prompt.sergiupopa.dev**, deployed with Deployer the same way as the user's `yum` project (D-19). The production topology needs four pieces:
- web: Hestia's nginx → Apache → PHP 8.4-FPM, serving `current/public`;
- a queue worker under systemd;
- the scheduler, run by a cron entry;
- PostgreSQL 17 on the droplet.

Queue, cache and sessions use Laravel's database stores, so there is no Redis (D-20). The worker and scheduler start out idle. Later stories only add jobs and schedules.

Pixelforge deliberately stays unconfigured in production until PE-015, so redeploying after every story never puts real money at risk. This serves the required public deployment, grading criterion #2 (robustness of the running system) and criterion #3 (reviewers can reach and use the app).

## Acceptance Criteria

**Server and deploy**

1. The app answers at `https://prompt.sergiupopa.dev` with a valid Let's Encrypt certificate, and `APP_URL` is that URL. Plain `http://` requests redirect to `https://` (Hestia's forced HTTPS).
2. `deploy.php` lives at the repo root and is modelled on `../yum/deploy.php`, using a global Deployer install, so there is no new Composer dependency. It:
   - uses `recipe/laravel.php` with repository `git@github.com:sergiu-popa/prompt-edit.git`, branch `main` and `keep_releases` 5;
   - sets `bin/php` and `bin/composer` to `/usr/bin/php8.4`;
   - targets host `164.90.236.77` as user `admin` with `deploy_path` `/home/admin/web/prompt.sergiupopa.dev`;
   - shares `.env` and `storage/` across releases, the recipe default;
   - runs `npm ci` and `npm run build` through nvm on the server, then removes `node_modules`;
   - runs `migrate --force` and the recipe's cache steps, then flips `current`;
   - after the flip, reloads PHP-FPM (the scoped sudoers rule, guarded as in yum) and runs `artisan queue:restart`, so the worker picks up the new release;
   - unlocks on failure.
3. **CI gate:** `dep deploy` refuses to deploy, with a clear message, unless the latest CI run for `origin/main`'s exact commit has succeeded. A local task checks this with `gh run list` before anything touches the server.
4. **Postgres 17:** the droplet runs PostgreSQL 17 from the PGDG apt repository.
   - The `prompt_edit` database is owned by a dedicated `prompt_edit` role with its own password.
   - It listens on localhost only.
   - `php8.4 artisan migrate:status` in `current/` lists every migration as ran.
5. **Database stores (D-20):** production sets `QUEUE_CONNECTION=database`, `CACHE_STORE=database` and `SESSION_DRIVER=database`. The `jobs`, `failed_jobs`, `cache`, `cache_locks` and `sessions` tables exist.
6. **Worker:** a systemd unit, `prompt-edit-worker.service`, runs `php8.4 artisan queue:work database` with the documented flags.
   - It runs as `admin` from `current/`, with `Restart=always`, and is enabled at boot.
   - After a deploy, `systemctl status prompt-edit-worker` shows it was restarted on the new release.
   - A job dispatched in production, such as `Artisan::queue('inspire')`, is processed.
7. **Scheduler:** a Hestia cron job for `admin` runs `php8.4 artisan schedule:run` every minute from `current/`. `php8.4 artisan schedule:list` runs without error.
8. **Backups:** a nightly cron writes a `pg_dump` of `prompt_edit` into the domain folder, keeping 7 days. Hestia's nightly backup of user `admin` already includes that folder. The README says how to restore it.
9. `GET /up` on production returns 200.

**Production-safe configuration**

10. **HTTPS and client IP behind Hestia's nginx → Apache chain:**
    - every URL the app generates uses `https://`: asset URLs, form actions, the guest → `/login` redirect and the post-login redirect, with no mixed-content warnings;
    - the client IP that Laravel sees, which the PE-003 sign-in throttle and the logs both use, is the visitor's public IP, never `127.0.0.1` or the server's IP;
    - trusted proxies are limited to what the server actually uses (loopback). Never `trustProxies(at: '*')`;
    - a feature test proves that a request from a trusted proxy carrying `X-Forwarded-Proto: https` gets an `https://…/login` redirect, and that the same header from an untrusted address is ignored.
11. **Cookies:** on production the session cookie is `Secure`, `HttpOnly` and `SameSite=Lax`. The `XSRF-TOKEN` cookie is `Secure` and not `HttpOnly`, because Inertia's HTTP client reads it. The production smoke asserts these flags.
12. **Errors:** production runs with `APP_ENV=production` and `APP_DEBUG=false`, and `php8.4 artisan about` shows debug off. An unknown URL returns the standard 404 page, with no stack trace, environment values or SQL. The smoke asserts this.
13. **Logs:** the `daily` channel writes to `shared/storage/logs`, keeping 14 days, at a level that keeps the AC14 info line. The README says where to find the logs and how to tail them.
14. **No Pixelforge configuration:** the production `.env` has no `PIXELFORGE_*` variables, and every page and `/up` works without them.
15. `.env.production.example` is committed with every production variable name and every non-secret value, like yum's. Secrets are left blank.

**Reviewer/demo account command**

16. An artisan command creates or updates a user by email (name, email, password). It runs non-interactively over SSH.
    - The email is lowercased, as in PE-003.
    - The password follows the registration rules. One under 8 characters is rejected with a non-zero exit code and no change.
    - Running it twice with the same email leaves exactly one user with the latest name and password, and exits 0 both times.
    - The password never appears in the command's output or in application logs.
    - It uses no factories or Faker, which are dev-only and absent from a `--no-dev` install.

**Smoke check and runbook**

17. An opt-in Playwright production smoke runs from a Makefile target. It takes `E2E_BASE_URL` and the reviewer credentials from environment variables. Against production it checks:
    - `/up` returns 200;
    - plain HTTP redirects to HTTPS;
    - the login page renders;
    - signing in with the reviewer account reaches the signed-in home, and signing out works;
    - the browser logs no console errors;
    - the AC11 cookie flags;
    - the AC12 plain 404.

    It never registers users and never writes data beyond a session. It is not part of `make e2e` or CI.
18. The README's deployment section contains:
    - the live URL;
    - the **one-time provisioning runbook**, as copy-pasteable commands:
      - Hestia web domain with the `deployer` template, the PHP 8.4 backend with the domain folder in `open_basedir`, and Let's Encrypt with forced HTTPS;
      - the Postgres 17 install, role and database;
      - the systemd unit and how to enable it;
      - the sudoers lines, scoped to the exact commands;
      - the scheduler and backup cron entries;
      - `shared/.env` created from `.env.production.example` with `chmod 600`;
    - the deploy runbook: CI green on `main`, then `dep deploy`. Rollback is `dep rollback`. Migrations are forward-only and must stay compatible with the previous release;
    - the rule that every merged story is redeployed and smoke-checked;
    - the note that production needs its **own** Pixelforge key, because the webhook URL, signing secret and `/v1/usage` are all per key;
    - the warning "don't submit generations on production before PE-015";
    - where the logs and backups live, and how to restore the database.
19. No secret is committed. The reviewer password, `APP_KEY` and the Postgres password live only in `shared/.env` on the server or in the operator's shell.

## Scope

### In Scope
- `deploy.php` with the CI gate, the FPM reload and the worker restart.
- `.env.production.example`.
- Production-safe config:
  - trusted proxies, HTTPS URLs and the client IP;
  - secure cookies;
  - debug off;
  - the daily log channel.
- The idempotent create/update user command, with feature tests.
- The opt-in Playwright production smoke and its Makefile target, kept out of `make e2e` and CI.
- The README deployment section and the one-time provisioning runbook (AC18).
- Server provisioning and the first deploy, done together with the user (see Technical Notes).

### Out of Scope
- Pixelforge key, webhook URL and secret, the fake/real driver switch, the live API smoke and the hardening checklist (PE-015). The checklist also decides whether public registration stays open once real Pixelforge spend is possible.
- Usage reconciliation and the final demo generations (PE-018).
- CI-triggered deploys, monitoring and alerting, staging, and off-droplet backups. Hestia keeps one nightly copy on the same disk, which yum's README already flags as a follow-up.

## Technical Notes
- **Decisions:** D-19 (own droplet with Deployer, Postgres 17 on the droplet, systemd worker, cron scheduler, deploys run by the assistant after each merge) and D-20 (database queue, cache and sessions; no Redis). D-13 (Laravel Cloud) is superseded.
- **Prerequisite (a bootstrap PR before this story):** dev, CI and tests move to the database cache store and drop the Redis containers, so dev matches production (D-20). This story assumes that has landed.
- **Reference implementation:** `../yum/deploy.php` and the "Producție" section of `../yum/README.md`. That section covers:
  - the Hestia template;
  - `open_basedir`;
  - the scoped sudoers rule for the FPM reload;
  - why the reload is needed (OPcache keeps serving the old release);
  - the `chmod u+w` the domain folder needs;
  - the client-IP check.

  Reuse what applies. yum has no worker, scheduler or Postgres, so those parts are new.
- **The droplet is shared** with yum and azsemaus. Nothing here may change their domains, PHP pools, databases or MariaDB. Postgres listens on 5432 on localhost, and MariaDB on 3306 is untouched.
- **Who does what:**
  - The pipeline writes the code, config, tests and runbook.
  - Server provisioning needs root on the shared droplet. It happens after code review approves and before QA:
    - the assistant may run read-only checks over SSH as `admin` (versions, `systemctl status`, `ls`);
    - **every root or state-changing command is confirmed with the user first**, or run by the user from the runbook;
    - the first deploy may be from the story branch (`dep deploy --branch=…`), since nothing is live yet;
    - from then on, only `main` is deployed.
  - QA then runs the production smoke against the live site.
- **Scheme and client IP:** yum works without a `trustProxies` call. Find out from the server how Hestia's nginx → Apache chain presents the scheme and client IP (mod_remoteip, `X-Forwarded-Proto`, HTTPS env), then choose the narrowest Laravel setting that satisfies AC10. The feature test pins that choice.
- **Worker details:**
  - The unit's `WorkingDirectory` is the `current` symlink. systemd resolves it when the process starts, so the `queue:restart` that follows a deploy (a graceful exit) plus `Restart=always` moves the worker onto the new release.
  - `--max-time` bounds how long one process lives.
  - The flags must stay consistent with PE-013's submit window: the job timeout must be less than the database queue's `retry_after`.
- **Scheduler:** a cron `schedule:run` every minute is enough for PE-014's every-minute reconciler. Inside the app, use `withoutOverlapping()`, which uses the database cache lock.
- **For PE-015:** Pixelforge follows no redirects and accepts only public URLs. The webhook URL must be the exact final `https://prompt.sergiupopa.dev/webhooks/pixelforge`, with no http→https hop and no trailing-slash redirect.
- **Account command:** registration stays open (PE-003). The command exists so reviewers have a ready account and the production smoke has stable credentials. How the password reaches the command is the Architect's call. The constraint is that it is never written to application logs. The README states where it may persist (for example shell history) and how to rotate it by re-running the command.
- The CI `e2e` job runs everything in `tests/e2e` (`playwright.config.ts` `testDir`). The production smoke must live in a separate project or directory that CI doesn't pick up.
- **Window before PE-015:** from PE-011 until PE-015, production has a submit form but no Pixelforge key, so don't submit generations there. Later stories must keep "boots without `PIXELFORGE_*`" true until PE-015 configures it.
- **Required test layers:**
  - PHP feature:
    - the create/update user command: create, idempotent update, lowercasing, the password rule, the password absent from output and logs;
    - HTTPS and the trusted-proxy behaviour (AC10);
    - pages and `/up` respond with no `PIXELFORGE_*` configuration (AC14).
  - E2E (Playwright, opt-in, production only): the AC17 smoke suite.
  - No JS unit or integration tests: there is no new UI.
- **Depends on:** PE-003-sign-in-and-app-shell, and the bootstrap PR that removes Redis.
- **PR size:** about 300 changed lines: `deploy.php`, `.env.production.example`, the command and its tests, proxy config, the Makefile target, the smoke spec and the README runbook.

## Mockups / References
No UI. The story reuses the PE-003 pages.

- `../yum/deploy.php` and `../yum/README.md` § Producție: the Deployer + HestiaCP setup this mirrors.
- Deployer Laravel recipe: https://deployer.org/docs/8.x/recipe/laravel
- PostgreSQL apt repository (PGDG): https://www.postgresql.org/download/linux/ubuntu/
- Laravel queues (database driver, `queue:restart`, supervising workers): https://laravel.com/docs/13.x/queues
- Laravel task scheduling (`schedule:run` cron, `withoutOverlapping`): https://laravel.com/docs/13.x/scheduling
- Laravel trusted proxies: https://laravel.com/docs/13.x/requests#configuring-trusted-proxies
- Pixelforge webhooks (per-key public URL, no redirects, 10 s window): https://pixelforge.promptedit.dev/docs#webhooks
- requirements.md §2, §2.5; decisions D-19, D-20
