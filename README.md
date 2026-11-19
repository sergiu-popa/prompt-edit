# PromptEdit × Pixelforge

Image generation on top of [Pixelforge](https://pixelforge.promptedit.dev/docs), a simulated asynchronous AI image API. It's being built as the take-home assessment for PromptEdit's Senior Product Engineer role. Users sign in, write a prompt, see what it will cost **before** they submit, and watch the generation finish live. They pay Pixelforge's exact cost **plus 25%**, and failed generations are free.

**Stack:** Laravel 13 · Inertia 3 · Vue 3 · TypeScript · Tailwind CSS 4 · PostgreSQL 17 · Docker · GitHub Actions.

## Status

| Area | State |
| --- | --- |
| Bootstrap (stack switch, Docker, CI, test harness, worker and scheduler) | Done as stacked PRs, to merge in order: #3 → #2 → #4 → #5 → #6 |
| Product features | Planned as 18 stories (below); each is delivered as one reviewable PR |
| Deployment | Live at https://prompt.sergiupopa.dev (Deployer, see [Production](#production)) since PE-004; real Pixelforge in PE-015 |

### Required scope

| Story | What it delivers |
| --- | --- |
| [PE-001](.agent-workflow/stories/PE-001-money-nanodollars-and-markup/story.md) | Money in integer nanodollars: exact provider cost and the +25% charge |
| [PE-002](.agent-workflow/stories/PE-002-upfront-estimate-shared-fixture/story.md) | Upfront estimate in PHP and TypeScript, locked together by one shared JSON fixture |
| [PE-003](.agent-workflow/stories/PE-003-sign-in-and-app-shell/story.md) | Sign in, sign up and sign out (Fortify + Inertia) with the app shell |
| [PE-004](.agent-workflow/stories/PE-004-walking-skeleton-own-server/story.md) | Walking skeleton live on prompt.sergiupopa.dev |
| [PE-005](.agent-workflow/stories/PE-005-generate-page-live-estimate/story.md) | Generate page with an instant upfront cost estimate |
| [PE-006](.agent-workflow/stories/PE-006-generation-record-and-transitions/story.md) | Generation record and the single forward-only transition action |
| [PE-007](.agent-workflow/stories/PE-007-credit-ledger-hold-settle-release/story.md) | Append-only credit ledger: hold the max estimate, charge the actual exactly once, failed is free |
| [PE-008](.agent-workflow/stories/PE-008-verified-idempotent-webhooks/story.md) | Signature-verified, idempotent, order-independent Pixelforge webhooks |
| [PE-009](.agent-workflow/stories/PE-009-pixelforge-client-and-submit-job/story.md) | Pixelforge client contract and the idempotent submit job |
| [PE-010](.agent-workflow/stories/PE-010-fake-pixelforge-driver/story.md) | In-app fake Pixelforge with signed webhooks and test keywords |
| [PE-011](.agent-workflow/stories/PE-011-submit-generation-and-history/story.md) | Submit a generation and see it in history |
| [PE-012](.agent-workflow/stories/PE-012-live-status-results-and-failure-copy/story.md) | Live status, image and actual cost in history; failures explained |
| [PE-013](.agent-workflow/stories/PE-013-provider-limits-retries-and-throttle/story.md) | Provider limits, overloads and outages: bounded retries, free failures and a shared rate budget |
| [PE-014](.agent-workflow/stories/PE-014-reconciliation-poller/story.md) | Scheduled reconciler: status and money catch up when webhooks don't arrive |
| [PE-015](.agent-workflow/stories/PE-015-production-go-live/story.md) | Go live with real Pixelforge: live smoke, production webhooks, hardening and first real generations |

### Stretch goals

| Story | What it delivers |
| --- | --- |
| [PE-016](.agent-workflow/stories/PE-016-operator-report/story.md) | Operator report: credit spend, provider cost and profit per day and per user |
| [PE-017](.agent-workflow/stories/PE-017-credit-balances-buy-credits/story.md) | Credit balances, fake 'Buy credits', and blocking unaffordable generations |
| [PE-018](.agent-workflow/stories/PE-018-usage-reconciliation-and-final-signoff/story.md) | Provider costs reconcile with GET /v1/usage; final production sign-off |

## How the money works

- **Integer nanodollars** (1 n$ = $0.000000001), stored as `BIGINT`, never floats. Pixelforge's per-1M-token rates are whole n$ per token (`"12.50"` → 12,500 n$/token). Provider cost is therefore exact and matches the 9-decimal `cost_usd` in `GET /v1/usage` digit for digit.
- **Billed at the generation's own rates.** Each generation is priced with the `rates` Pixelforge returns on it, never with a hard-coded table.
- **User price = ⌈provider × 125 / 100⌉.** It rounds up to the next nanodollar, so it never under-charges and over-charges by at most 0.75 n$.
- **The upfront estimate is an honest range.** Text tokens are ±15% of ⌈code points / 4⌉ and reasoning tokens vary by quality. One shared JSON fixture keeps the PHP and TypeScript estimators identical.
- **Exactly once, enforced by the database.** An append-only ledger holds the maximum at submit, charges the actual once on success (a unique constraint, not a convention) and releases the hold on failure.
- **Reconciles with Pixelforge.** The operator report buckets costs by Pixelforge's `completed_at` UTC day, the same way `/v1/usage` does.

Worked examples and the full model are in [`requirements.md` §3](.agent-workflow/requirements.md#3-money-model).

## How it copes with Pixelforge

- **Local row first.** Its ULID is both the `Idempotency-Key` and `metadata.generation_id`. Retries are safe, and a webhook that arrives before the create response still finds its row.
- **Webhooks are verified, deduplicated and order-independent.** The HMAC-SHA256 signature over `"<t>.<raw body>"` is checked with a 5-minute tolerance. The event id is deduplicated in the same transaction as the state change, and the state machine only moves forward: a late `processing` can never undo `succeeded`.
- **Limits and overloads.** `429` (rate limit or concurrency) and `503` are retried, honouring `Retry-After` and reusing the same idempotency key. Definitive rejections (content policy, validation) fail free with a clear message, and so does the daily limit.
- **Lost webhooks.** A scheduled reconciler polls stuck generations through the same transition path.

Every failure mode is covered in the [robustness matrix](.agent-workflow/requirements.md#4-robustness-matrix). The reasoning behind each choice is in the [decisions log](.agent-workflow/requirements.md#5-decisions-log).

## Local development

**Requirements:** Docker and Docker Compose. `prompt-edit.localhost` resolves automatically on macOS; on Linux, add it to `/etc/hosts`.

```bash
make setup   # .env from .env.example, containers, composer + npm, key, migrations, assets
```

`make setup` **overwrites** an existing `.env`. Once your Pixelforge key is in it, use `make up` instead.

The app is served at http://prompt-edit.localhost. `make npm-dev` runs Vite in dev mode.

`make seed` creates a local demo account, and running it again resets it:

| Email | Password |
| --- | --- |
| `demo@promptedit.test` | `promptedit-demo` |

These credentials are local-only: the seeder refuses to run in production.

### Pixelforge (used from PE-009 onwards)

- Create **one API key per environment**: local, production. Each key has its own webhook URL, signing secret and `/v1/usage` report, so the deployed app reconciles with its own key.
- Put the key and its webhook signing secret in `.env` as `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`, and never commit them. `PIXELFORGE_DRIVER` switches between the real API and the in-app fake (PE-010), which also drives E2E.
- To receive real webhooks locally, expose the app through a tunnel and set `https://<tunnel>/webhooks/pixelforge` as the key's webhook URL in the Pixelforge dashboard. For example:
  - `cloudflared tunnel --url http://localhost:80 --http-host-header prompt-edit.localhost`
  - `ngrok http 80 --host-header=prompt-edit.localhost`
- Prompt keywords force behaviour for testing: `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`, `[succeed]`.

## Production

Live at **https://prompt.sergiupopa.dev**, deployed with [Deployer](https://deployer.org) (`deploy.php`, D-19) on the HestiaCP droplet `164.90.236.77`, user `admin`, in `/home/admin/web/prompt.sergiupopa.dev`.

- **Web:** Hestia's nginx (Let's Encrypt, forced HTTPS) → Apache with the `deployer` template (DocumentRoot `current/public`) → PHP 8.4-FPM running as `admin` (backend `Symfony-PHP-8_4`, whose `open_basedir` is the whole domain folder). Apache sees `HTTPS=on` and mod_remoteip gives PHP the visitor's IP, so Laravel trusts no proxy (D-21). The chain does pass a client's `X-Forwarded-Host` on to PHP as the Host, so the app answers only for its own host and its subdomains (`trustHosts()`), and any other host gets a 400.
- **Queue worker:** the systemd unit `prompt-edit-worker` (`queue:work database`, `WorkingDirectory` = `current`).
- **Scheduler:** a Hestia cron job for `admin` runs `schedule:run` every minute. It also runs the nightly backup.
- **Database:** PostgreSQL 17 (PGDG) on localhost:5432, database and role `prompt_edit`. Queue, cache and sessions use it too; there is no Redis (D-20).
- The droplet is shared with yum and azsemaus. Their domains, PHP pools, MariaDB and Redis are untouched.

### One-time provisioning (root)

Run once, as root on the droplet, in **one shell session** (step 4 reuses `$PE_DB_PASSWORD`). DNS: an `A` record `prompt.sergiupopa.dev` → `164.90.236.77`, and the Hestia web domain `prompt.sergiupopa.dev` for user `admin`.

```bash
D=/home/admin/web/prompt.sergiupopa.dev

# 1. Hestia: Deployer layout on PHP 8.4, Let's Encrypt with forced HTTPS
v-change-web-domain-tpl admin prompt.sergiupopa.dev deployer yes
v-change-web-domain-backend-tpl admin prompt.sergiupopa.dev Symfony-PHP-8_4 yes
v-add-letsencrypt-domain admin prompt.sergiupopa.dev
v-add-web-domain-ssl-force admin prompt.sergiupopa.dev
chmod u+w "$D"                                       # or Deployer can't create releases/
install -d -m 750 -o admin -g admin "$D/shared"
install -d -m 700 -o admin -g admin "$D/private/backups"
sudo -u admin git ls-remote git@github.com:sergiu-popa/prompt-edit.git HEAD   # admin can read the repo

# 2. PostgreSQL 17 from the PGDG apt repository
apt-get install -y postgresql-common
/usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
apt-get install -y postgresql-17
cat > /etc/postgresql/17/main/conf.d/prompt-edit.conf <<'CONF'
# prompt.sergiupopa.dev on a shared 2 GB droplet
listen_addresses = 'localhost'
max_connections = 30
shared_buffers = 128MB
effective_cache_size = 384MB
work_mem = 4MB
maintenance_work_mem = 32MB
CONF
systemctl restart postgresql
ss -ltn | grep 5432                                  # 127.0.0.1:5432 (and [::1]) only

# 3. The role and the database. The password is generated and never echoed. No secret goes on
#    a command line: sudo logs its full argv to /var/log/auth.log and every local user sees
#    argv in ps. The shell's printf builtin builds the SQL and psql reads it from stdin; the
#    password is hex, so it needs no quoting.
PE_DB_PASSWORD="$(openssl rand -hex 24)"
printf "CREATE ROLE prompt_edit LOGIN PASSWORD '%s';\nCREATE DATABASE prompt_edit OWNER prompt_edit ENCODING 'UTF8' TEMPLATE template0;\n" "$PE_DB_PASSWORD" \
  | sudo -u postgres psql -q -v ON_ERROR_STOP=1

# 4. shared/.env from the committed template (copy it up first, from your checkout:
#    scp .env.production.example root@164.90.236.77:/root/), secrets filled in place
install -m 600 -o admin -g admin /root/.env.production.example "$D/shared/.env"
APP_KEY="base64:$(openssl rand -base64 32)" DB_PASSWORD="$PE_DB_PASSWORD" \
  perl -i -pe 's/^APP_KEY=$/APP_KEY=$ENV{APP_KEY}/; s/^DB_PASSWORD=$/DB_PASSWORD=$ENV{DB_PASSWORD}/' "$D/shared/.env"
unset PE_DB_PASSWORD
ls -l "$D/shared/.env"                               # -rw------- admin admin

# 5. The queue worker: enabled now, started after the first deploy
cat > /etc/systemd/system/prompt-edit-worker.service <<'UNIT'
[Unit]
Description=PromptEdit queue worker (prompt.sergiupopa.dev)
After=network.target postgresql.service

[Service]
User=admin
Group=admin
WorkingDirectory=/home/admin/web/prompt.sergiupopa.dev/current
ExecStart=/usr/bin/php8.4 artisan queue:work database --sleep=3 --tries=3 --max-time=3600 --memory=128 --no-interaction
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable prompt-edit-worker

# 6. sudo for admin, scoped to the exact commands. The FPM reload line already exists (yum):
#    admin ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
cat > /etc/sudoers.d/prompt-edit-worker <<'SUDO'
admin ALL=(root) NOPASSWD: /usr/bin/systemctl start prompt-edit-worker, /usr/bin/systemctl stop prompt-edit-worker, /usr/bin/systemctl restart prompt-edit-worker
SUDO
chmod 440 /etc/sudoers.d/prompt-edit-worker
visudo -cf /etc/sudoers.d/prompt-edit-worker

# 7. The scheduler, through Hestia (it overwrites a hand-edited crontab). No backup cron:
#    the scheduler runs app:backup-database at 04:30 UTC.
v-add-cron-job admin '*' '*' '*' '*' '*' 'cd /home/admin/web/prompt.sergiupopa.dev/current 2>/dev/null && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1'

# 8. Server-wide, once per droplet: make nginx overwrite a client's X-Forwarded-Host and
#    X-Forwarded-Proto. Hestia's mod_rpaf has RPAFsethostname On and nginx used to pass the
#    client's own X-Forwarded-Host through, so every site on the droplet accepted a spoofed host
#    (found by the PE-004 audit, fixed 2026-11-19). The app's trustHosts() is the second guard.
#    Re-check after Hestia upgrades:
#      curl -sI -H 'X-Forwarded-Host: attacker.example' https://prompt.sergiupopa.dev/dashboard
#    must redirect to prompt.sergiupopa.dev, not attacker.example.
cp /etc/nginx/nginx.conf /etc/nginx/nginx.conf.bak-$(date +%F)
sed -i 's/^\(\s*\)proxy_set_header\s\+X-Real-IP\s\+\$remote_addr;/&\n\1proxy_set_header                X-Forwarded-Host $host;\n\1proxy_set_header                X-Forwarded-Proto $scheme;/' /etc/nginx/nginx.conf
nginx -t && systemctl reload nginx
```

The worker's flags keep each job's timeout (60 s by default) under the database queue's `retry_after` (90 s), and `--max-time` recycles the process hourly.

### Reviewer account

`app:upsert-user` creates or updates a user by email. The password is read from the first line of stdin, never from an argument, so it isn't visible in `ps` on the shared droplet or saved in the server's shell history (D-23). Run on a terminal without a pipe, it refuses rather than echo the password as you type it. From your machine:

```bash
read -rs E2E_REVIEWER_PASSWORD; export E2E_REVIEWER_PASSWORD   # not echoed, not in history
export E2E_REVIEWER_EMAIL=reviewer@example.com                   # the reviewer's address
printf '%s\n' "$E2E_REVIEWER_PASSWORD" | ssh admin@164.90.236.77 \
  "cd /home/admin/web/prompt.sergiupopa.dev/current && php8.4 artisan app:upsert-user '$E2E_REVIEWER_EMAIL' Reviewer"
```

The password follows the registration rules (at least 8 characters). It lives only in your shell session and your password manager, never in a remote command line, the server's history or the app log, which records only the email and user id. To rotate it, run the same command with a new password. Existing sessions stay valid until they expire (120 minutes).

### First deploy

```bash
dep deploy --branch=pe-004-walking-skeleton-own-server   # the CI gate needs green CI on that branch's head
ssh admin@164.90.236.77 sudo systemctl start prompt-edit-worker
```

Then create the reviewer account (above), run `make smoke-production` (below), and check, as `admin` in `current/`:

```bash
php8.4 artisan migrate:status                # every migration Ran
php8.4 artisan about                         # production, debug off, database cache/queue/session
php8.4 artisan schedule:list                 # app:backup-database at 30 4 * * *
php8.4 artisan tinker --execute 'Artisan::queue("inspire");'
php8.4 artisan tinker --execute 'echo DB::table("jobs")->count();'   # 0 once the worker took it
systemctl status prompt-edit-worker          # active, started on the new release
```

`journalctl -u prompt-edit-worker` (as root) shows the processed `inspire` job.

**Client IP:** sign in once with a wrong password and a throwaway email, then look for the throttle key:

```bash
php8.4 artisan tinker --execute 'echo DB::table("cache")->where("key", "like", "%throwaway%")->pluck("key");'
```

The key ends in `<email>|<ip>`, and the IP must be your public IP, never `127.0.0.1` or `164.90.236.77`. Never fix a wrong IP with `trustProxies(at: '*')`, which would let any visitor choose their throttle bucket.

### Deploy runbook

1. Merge to `main` and wait for CI to pass. `dep deploy` refuses to deploy unless the latest CI run on `origin/main`'s head succeeded, and it deploys exactly that commit. The gate runs locally and needs an authenticated `gh`.
2. `dep deploy`. It installs Composer and npm dependencies, builds the assets (through nvm, then removes `node_modules`), runs `migrate --force` and the caches, flips `current`, reloads PHP-FPM (otherwise OPcache keeps serving the old release) and restarts the queue worker (`artisan reload`).
3. `make smoke-production`.

**Every merged story is redeployed and smoke-checked.** Rollback is `dep rollback`, which also reloads PHP-FPM and restarts the worker. It doesn't roll migrations back, so migrations are forward-only and must stay compatible with the previous release.

The smoke runs Playwright in the `playwright` service (`make up` first) against production. It checks `/up`, the http → https redirect, a plain 404, that a spoofed `X-Forwarded-Host` never reaches a URL, the sign-in page and its cookie flags, no mixed content and no console errors, then signs the reviewer in and out. It never registers users.

```bash
export E2E_BASE_URL=https://prompt.sergiupopa.dev   # plus E2E_REVIEWER_EMAIL and E2E_REVIEWER_PASSWORD, as above
make smoke-production
```

### Pixelforge on production

Production needs its **own** Pixelforge key, because the webhook URL, the signing secret and `/v1/usage` are all per key. PE-015 sets it up. Until then `shared/.env` has no `PIXELFORGE_*` variables, and every page and `/up` work without them.

**Don't submit generations on production before PE-015.**

### Logs

- Laravel: `shared/storage/logs/laravel-YYYY-MM-DD.log`, one file per day, kept 14 days, at level `info`.
  ```bash
  tail -f /home/admin/web/prompt.sergiupopa.dev/shared/storage/logs/laravel-$(date +%F).log
  ```
- Apache and nginx: `/var/log/apache2/domains/prompt.sergiupopa.dev.*` and `/var/log/nginx/domains/`.
- Queue worker: `journalctl -u prompt-edit-worker` (as root).

### Backups and restore

At 04:30 UTC the scheduler runs `app:backup-database`, a `pg_dump --format=custom` into `private/backups/prompt_edit-<weekday>.dump`, so seven daily files rotate (D-22). A failed dump never replaces last week's file. Hestia's nightly backup of `admin` (05:10) carries the folder into `/backup/admin.<date>.tar`. That is one copy on the same disk; off-droplet backups are out of scope, as in yum.

To restore, as `admin`:

```bash
cd /home/admin/web/prompt.sergiupopa.dev
sudo systemctl stop prompt-edit-worker
PGPASSWORD="$(grep '^DB_PASSWORD=' shared/.env | cut -d= -f2-)" \
  pg_restore --clean --if-exists --no-owner --single-transaction \
  -h 127.0.0.1 -U prompt_edit -d prompt_edit private/backups/prompt_edit-<weekday>.dump
sudo systemctl start prompt-edit-worker
```

For an older copy, list Hestia's archives with `v-list-user-backups admin` (as root) and extract the dump from `/backup/admin.<date>.tar`.

## How this is built

- **Requirements first.** [`requirements.md`](.agent-workflow/requirements.md) distils the brief and the Pixelforge docs, checked against the live API, into facts, a money model, a robustness matrix and a decisions log.
- **Stories, then an agent pipeline.** Each [story](.agent-workflow/stories) moves through *Architect* (`plan.md`) → *Engineer* (code and tests) → *Reviewer* (`review.md`) → *QA* (`qa.md`, including a browser pass), with an optional *Auditor* for risky stories. The whole decision trail is committed next to the code. Project rules for the agents are in [`.agent-workflow/CLAUDE.md`](.agent-workflow/CLAUDE.md).
- **Bootstrap work has no stories.** Tooling such as the stack switch, CI and the test harness goes on `bootstrap/*` branches with `[bootstrap]` commits.
- **Git hygiene.** One story = one PR of about 400 changed lines, short `[PE-NNN]` commits that each carry their own tests, and TDD throughout.
- **AI tools.** Built with Claude Code. Every prompt is logged verbatim in [`.agent-workflow/prompts.md`](.agent-workflow/prompts.md).

## Make Targets

All commands run inside the `prompt-edit-app` container.

### Containers
- `make up` / `make down` / `make restart` — start, stop, restart the stack
- `make bash` — interactive shell in the app container
- `make tinker` — `php artisan tinker`
- `make app-logs` — tail the app container logs

### Database
- `make migrate` / `make fresh` / `make seed` / `make refresh`
- `make migrate-test` / `make fresh-test` / `make refresh-test` — same commands against the isolated test Postgres container

### Frontend
- `make npm` — install npm dependencies
- `make npm-dev` — run Vite dev server
- `make npm-build` — production build
- `make js-check` / `make js-fix` — ESLint, Prettier and vue-tsc

### Quality
- `make lint` / `make lint-fix` — Pint
- `make stan` — PHPStan / Larastan
- `make test` — PHPUnit (optionally `make test filter=testName`)
- `make test-unit` / `make test-feature`
- `make js-test` — Vitest unit + integration projects
- `make e2e` — production build, then Playwright (desktop + mobile) in the `playwright` Docker service; `make e2e filter="name"` to grep
- `make check` — lint + stan + test + js-check + js-test
- `make smoke-production` — opt-in Playwright smoke against production (needs `E2E_BASE_URL`, `E2E_REVIEWER_EMAIL`, `E2E_REVIEWER_PASSWORD`); never part of `make e2e` or CI

## Testing

| Layer | Tool | Location | Run |
| --- | --- | --- | --- |
| PHP unit and feature (HTTP, Inertia props, jobs) | PHPUnit against `test-postgres` | `tests/Unit`, `tests/Feature` | `make test` |
| JS unit — pure `resources/js/lib` functions and composables | Vitest (`unit` project, node) | `tests/js/unit` | `make js-test` |
| JS integration — mounted components and pages, Inertia mocked | Vitest + Vue Test Utils (`integration` project, jsdom) | `tests/js/integration` | `make js-test` |
| End-to-end — critical user flows in a real browser | Playwright (`playwright` Compose service) | `tests/e2e` | `make e2e` |
| Production smoke — opt-in, never in CI | Playwright (`playwright.production.config.ts`) | `tests/smoke` | `make smoke-production` |

Every Playwright test also fails if the browser logs a console or page error (`tests/e2e/fixtures.ts`).

E2E signs in as the demo account, so run `make seed` once before `make e2e`. Each run also registers a few `e2e-…@promptedit.test` users in the local database, which is harmless.

## Docker Services

Defined in `docker-compose.yml`:

| Service | Container | Purpose |
| --- | --- | --- |
| `traefik` | `prompt-edit-traefik` | Reverse proxy, exposes the app at `prompt-edit.localhost` |
| `app` | `prompt-edit-app` | PHP 8.4 application container (built from `.docker/Dockerfile`) |
| `worker` | `prompt-edit-worker` | Queue worker (`queue:work`) |
| `scheduler` | `prompt-edit-scheduler` | Scheduler (`schedule:work`) |
| `postgres` / `test-postgres` | `prompt-edit-postgres` / `-test-postgres` | Application and tmpfs-backed test databases (PostgreSQL 17) |
| `playwright` | `prompt-edit-playwright` | Playwright 1.65.1 browsers (profile `e2e`; shares the app container's network) |

Xdebug is available by setting `XDEBUG_MODE` in `.env`.

## CI Pipeline

`.github/workflows/ci.yml` runs on every push and is organized into parallel jobs:

1. **Build image** — builds the `ci` target of `.docker/Dockerfile`, pushes/caches it against a branch tag, and shares it with downstream jobs via the GitHub Actions cache.
2. **Code style** — `composer lint` (Pint in `--test` mode).
3. **Frontend checks** — `npm ci`, ESLint, Prettier check, `vue-tsc`, Vitest and a production build on Node 22.
4. **Static analysis** — `composer stan` (PHPStan with result cache restored from the `main` branch).
5. **Tests** — `php artisan migrate` followed by `php artisan test --coverage --min=80` against a Postgres service container. **Builds fail if coverage drops below 80%.**
6. **E2E** — boots the CI image against a Postgres service and runs Playwright inside the app container's network; uploads the report on failure.
7. **Docker push** — publishes the image to GHCR once all checks pass.
8. **Cleanup** — deletes the per-run image cache entry.

Required secrets: `GITHUB_TOKEN` (provided by Actions).

## Project Layout Notes

- `.docker/` — Dockerfile (multi-stage: `development`, `ci`, etc.), entrypoint, Apache/PHP/supervisor config.
- `.agent-workflow/` — requirements analysis, story backlog, agent configuration and the prompt log (see *How this is built*).
- `CLAUDE.md` / `AGENTS.md` — guidelines for AI coding agents; Laravel Boost rules and the Docker command convention.
- `boost.json` — Laravel Boost configuration.
- `phpstan.neon`, `pint.json`, `phpunit.xml` — quality-tool configuration.

## License

MIT.
