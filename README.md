# PromptEdit × Pixelforge

Image generation on top of [Pixelforge](https://pixelforge.promptedit.dev/docs), a simulated asynchronous AI image API. It's being built as the take-home assessment for PromptEdit's Senior Product Engineer role. Users sign in, write a prompt, see what it will cost **before** they submit, and watch the generation finish live. They pay Pixelforge's exact cost **plus 25%**, and failed generations are free.

**Stack:** Laravel 13 · Inertia 3 · Vue 3 · TypeScript · Tailwind CSS 4 · PostgreSQL 17 · Docker · GitHub Actions.

## Reviewer guide

- **Live app:** https://prompt.sergiupopa.dev, backed by the real Pixelforge API.
- **Signing in:** the reviewer account is shared with you privately and never appears in this repo. You can also create your own account: each network may create 3 accounts an hour (D-57).
- **Test keywords:** put any of these anywhere in a prompt to force Pixelforge's behaviour:

  | Keyword | What you see |
  | --- | --- |
  | `[succeed]` | Succeeds, never failing at random: the image, the actual cost and the charge |
  | `[fail]` | Accepted, then Failed with the failure copy and "Not charged" |
  | `[blocked]` | Rejected by the content policy at submit, "Not charged" |
  | `[overloaded]` | "Pixelforge is busy — retrying automatically", then Failed after 2 min, "Not charged" |
  | `[slow]` | Processing for 60–90 s, then Succeeded without a reload |
  | `[duplicate]` | Every webhook arrives twice, and the generation is still charged once |

  The reviewer account's History holds a few real generations made with them at go-live.
- **The money model:**
  - amounts are integer nanodollars (1 n$ = $0.000000001), never floats;
  - you pay Pixelforge's exact cost + 25 %, rounded up to the next nanodollar;
  - submitting holds the estimate's maximum, and completion settles the actual cost;
  - a failed or rejected generation is free and its hold is released.

  Details are in [How the money works](#how-the-money-works).
- **Operator report:** the reviewer account is an operator: Report shows spend, Pixelforge cost and profit per UTC day and per user, exact to the nanodollar on hover.
- **Why it is built this way:** every choice is in the [decisions log](.agent-workflow/requirements.md#5-decisions-log).
- **Running the tests:** see [Testing](#testing). `make check` runs PHP lint, static analysis, PHPUnit, the frontend checks and Vitest; `make e2e` runs Playwright against the in-app fake. `make live-smoke` is the one opt-in test against the real API (D-58): it reads a **dev** key from `.env` as `PIXELFORGE_API_KEY`, never the production key, because that would add to production's `/v1/usage` and break its reconciliation (PE-018). It refuses to run in production, in CI and without a key, and costs at most $0.0175 a run.

## Status

| Area | State |
| --- | --- |
| Bootstrap (stack switch, Docker, CI, test harness, worker and scheduler) | Done as stacked PRs, to merge in order: #3 → #2 → #4 → #5 → #6 |
| Product features | Planned as 18 stories (below); each is delivered as one reviewable PR |
| Deployment | Live at https://prompt.sergiupopa.dev (Deployer, see [Production](#production)) since PE-004, on the real Pixelforge API since PE-015 |

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

### Balances and Buy credits

- **The balance is the ledger.** There is no balance column that could drift (D-67). Available is the sum of a user's entries: grants and purchases add, holds of running generations and charges take. Reserved is what the active holds take. Provider cost lives on the generation, never in the ledger, so it never moves a balance.
- **Every account starts with $1.0000.** Registration, `app:upsert-user` (on create only) and the seeders each write one starter grant in the transaction that creates the user (`CREDITS_STARTER_GRANT`, D-70). A partial unique index allows one grant per user.
- **An unaffordable generation is refused up front.** The create action locks the user's row, reads the balance and refuses a max estimate it can't cover, all in the transaction that writes the hold (D-68). Two tabs therefore can't overspend. Generate shows the shortfall, rounded up to the next $0.0001, with a link to Buy credits, and the browser's check only disables the button early.
- **The actual can exceed the hold** when Pixelforge's rates change between estimate and creation. The charge is settled in full, a warning is logged, the balance may go negative ("-$0.0010") and every create is refused until a top-up.
- **Buy credits is fake.** The page at `/credits` offers $1.00, $5.00 and $10.00 packs, says "Demo — no payment is taken" and has no payment fields. Each visit sends one purchase token, unique per user in the database, so a double click adds one pack (D-71).
- **Existing accounts got an opening grant.** `app:grant-opening-credits` gives every user without a grant the starter plus their settled charges, so they start where a new account starts. It runs on every deploy and finds nobody after go-live.

## How it copes with Pixelforge

- **Local row first.** Its ULID is both the `Idempotency-Key` and `metadata.generation_id`. Retries are safe, and a webhook that arrives before the create response still finds its row.
- **Webhooks are verified, deduplicated and order-independent.** The HMAC-SHA256 signature over `"<t>.<raw body>"` is checked with a 5-minute tolerance. The event id is deduplicated in the same transaction as the state change, and the state machine only moves forward: a late `processing` can never undo `succeeded`.
- **Limits and overloads.** Every call takes from one shared budget of 50 requests a minute, below Pixelforge's 60. A `429` rate limit or concurrency limit and a `503` are retried after `Retry-After`, with the same idempotency key and byte-identical body. A slot that never frees up (10 min) or an overload that lasts (2 min) fails free; so does the daily limit, at once, and a key refused 3 times, with one critical log. History says "Pixelforge is busy — retrying automatically" or "Waiting for a free slot" while it waits. Definitive rejections (content policy, validation) fail free with a clear message.
- **Ambiguity is never failed.** A timeout, a 5xx or an unreadable answer may have created the generation, so it is retried with the same key and never failed locally. The submit job is bounded by time (30 min), not by tries; if the generation is still ambiguous then, it stays pending with its hold and is handed to the reconciler.
- **Lost webhooks.** A scheduled reconciler runs every minute. It polls generations Pixelforge confirmed but has sent no news about for 2–3 min, through the same transition path, so a poll and a webhook settle exactly once in either order. It sends at most 20 polls a run, always leaving 10 budget units of the minute to new submits, and checks each generation less often as it ages (every 1 min, up to every 15 min).
- **Dead or handed-over submits.** A generation Pixelforge never confirmed, whose submit job was handed over or stopped 5 min ago, is re-dispatched with the same ULID: Pixelforge replays a create that landed or creates it now. A per-generation lock keeps one create in flight. A day after creation it is given up as `lost` and free; one Pixelforge confirmed is never given up, only polled on.
- **Taking longer than usual.** After 15 min, a generation that is not done says "Taking longer than usual — we're checking with Pixelforge" on History.

Every failure mode is covered in the [robustness matrix](.agent-workflow/requirements.md#4-robustness-matrix). The reasoning behind each choice is in the [decisions log](.agent-workflow/requirements.md#5-decisions-log).

## Local development

**Requirements:** Docker and Docker Compose. `prompt-edit.localhost` resolves automatically on macOS; on Linux, add it to `/etc/hosts`.

```bash
make setup   # .env from .env.example, containers, composer + npm, key, migrations, assets
```

`make setup` **overwrites** an existing `.env`. Once your Pixelforge key is in it, use `make up` instead.

The app is served at http://prompt-edit.localhost. `make npm-dev` runs Vite in dev mode.

`make seed` creates the local accounts, and running it again resets them:

| Email | Password | Role |
| --- | --- | --- |
| `demo@promptedit.test` | `promptedit-demo` | user |
| `operator@promptedit.test` | `promptedit-operator` | operator: opens Report |

These credentials are local-only: the seeder refuses to run in production.

Dev and E2E accounts start at $0.0100 (`CREDITS_STARTER_GRANT=10000000`), below the cheapest request's max, so the blocked flow can be seen and tested (D-71). Buy a pack at `/credits`. The seeded demo account also gets one $1.00 demo purchase, so it has $1.0100 on a fresh database.

### Pixelforge (used from PE-009 onwards)

- Local dev and E2E use the **in-app fake** by default (`PIXELFORGE_DRIVER=fake` in `.env.example`). It needs no key and no public URL: the worker moves each generation along on delayed jobs and posts signed webhooks to the in-network app at `http://app:8080/webhooks/pixelforge`. `make setup` fills `PIXELFORGE_WEBHOOK_SECRET` with a random local value for it to sign with. The app refuses to boot with the fake in production.
- To use the real API instead, create **one API key per environment**: local, production. Each key has its own webhook URL, signing secret and `/v1/usage` report, so the deployed app reconciles with its own key. Set `PIXELFORGE_DRIVER=http`, the key as `PIXELFORGE_API_KEY` and its `whsec_…` as `PIXELFORGE_WEBHOOK_SECRET`, never commit them, then `make restart-worker` (the worker reads `.env` once).
- An existing checkout picks up the fake with `PIXELFORGE_DRIVER=fake`, `make webhook-secret` (it keeps a secret already there) and `make restart-worker`.
- Prompt keywords force behaviour for testing: `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`, `[succeed]`. The fake adds `[expire]`, whose image URL dies after a few seconds (`PIXELFORGE_FAKE_EXPIRE_TTL_SECONDS`, default 5).

#### Real webhooks locally

The fake driver (PE-010) stays the default and needs none of this. To receive real Pixelforge webhooks on your machine:

1. Expose the app through a tunnel, for example:
   - Cloudflare Tunnel: `cloudflared tunnel --url http://localhost:80 --http-host-header prompt-edit.localhost`
   - ngrok: `ngrok http 80 --host-header=prompt-edit.localhost`
   - Expose: `expose share http://prompt-edit.localhost`
2. In the Pixelforge dashboard, set your **dev** API key's webhook URL to `https://<tunnel>/webhooks/pixelforge`.
3. Copy that key's `whsec_…` signing secret into `.env` as `PIXELFORGE_WEBHOOK_SECRET`. `.env` is never committed.
4. Press **Send test event** in the dashboard. The `ping` must get a 2xx. `storage/logs/laravel.log` shows why a delivery was refused:
   - `missing`, `malformed`, `mismatch` or `stale` is a signature problem, answered 400. Usually the secret belongs to another key, or the clock is off by more than 5 minutes.
   - A critical "no webhook secret is configured" line, answered 500, means `PIXELFORGE_WEBHOOK_SECRET` is empty.

The endpoint sits outside the session, CSRF and auth middleware, and verifies the HMAC over the exact raw body. Each event id is recorded once, in the same transaction as the change it causes, so duplicates and resends are no-ops.

## Production

Live at **https://prompt.sergiupopa.dev**, deployed with [Deployer](https://deployer.org) (`deploy.php`, D-19) on the HestiaCP droplet `164.90.236.77`, user `admin`, in `/home/admin/web/prompt.sergiupopa.dev`.

- **Web:** Hestia's nginx (Let's Encrypt, forced HTTPS) → Apache with the `deployer` template (DocumentRoot `current/public`) → PHP 8.4-FPM running as `admin` (backend `Symfony-PHP-8_4`, whose `open_basedir` is the whole domain folder). Apache sees `HTTPS=on` and mod_remoteip gives PHP the visitor's IP, so Laravel trusts no proxy (D-21). The chain does pass a client's `X-Forwarded-Host` on to PHP as the Host, so the app answers only for its own host and its subdomains (`trustHosts()`), and any other host gets a 400.
- **Queue worker:** the systemd unit `prompt-edit-worker` (`queue:work database`, `WorkingDirectory` = `current`).
- **Scheduler:** a Hestia cron job for `admin` runs `schedule:run` every minute. It runs the reconciler (`app:reconcile-generations`, every minute on one server, D-54) and the nightly backup, so PE-014 needed no droplet change.
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

# 5. The queue worker: enabled now, started after the first deploy. --tries=3 bounds the other
#    jobs; the submit job is bounded by time instead, and its retryUntil overrides it (D-46).
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

### Operator access

`app:grant-operator` makes an existing account an operator, who sees Report in the nav and may open the operator report (D-62). Create the account first with `app:upsert-user` (above), then, from your machine:

```bash
ssh admin@164.90.236.77 \
  "cd /home/admin/web/prompt.sergiupopa.dev/current && php8.4 artisan app:grant-operator '$E2E_REVIEWER_EMAIL'"
```

It is idempotent: running it again prints "… is already an operator." and exits 0. It never creates users or sets passwords, and an unknown email exits 1 without writing anything. There is no UI for operator access and no revoke.

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
2. `dep deploy`. It installs Composer and npm dependencies, builds the assets (through nvm, then removes `node_modules`), runs `migrate --force` and the caches, then `app:grant-opening-credits` so no account is served a balance without its grant (safe to re-run), flips `current`, reloads PHP-FPM (otherwise OPcache keeps serving the old release) and restarts the queue worker (`artisan reload`).
3. `make smoke-production`.

**Every merged story is redeployed and smoke-checked.** Rollback is `dep rollback`, which also reloads PHP-FPM and restarts the worker. It doesn't roll migrations back, so migrations are forward-only and must stay compatible with the previous release.

The smoke runs Playwright in the `playwright` service (`make up` first) against production. It checks `/up`, `/health/reconciler`, that the webhook URL answers an unsigned delivery with a 400 and no redirect, the http → https redirect, a plain 404, that a spoofed `X-Forwarded-Host` never reaches a URL, the sign-in page and its cookie flags, no mixed content and no console errors, then signs the reviewer in, checks History and the operator report read-only, and signs out (D-76). History must show a succeeded generation with its charge and a loaded image, so that check needs an image less than 24 h old; the report must show every day and the total as Match against `/v1/usage`, reloading for up to 3 min. Its only writes are sign-in and sign-out. It never registers users.

```bash
export E2E_BASE_URL=https://prompt.sergiupopa.dev   # plus E2E_REVIEWER_EMAIL and E2E_REVIEWER_PASSWORD, as above
make smoke-production
```

### Pixelforge on production

Production runs the real API with its **own dedicated key**. The webhook URL, the signing secret and `/v1/usage` are all per key, so production's usage report only ever counts production's generations. Rate limits are per account, so every key shares the 60 requests a minute, 3 in flight and 500 a day.

#### Per-environment runbook

Variable names only: no key or secret is ever committed, echoed, logged or put on a command line.

- **Local with the fake driver** (the default): `make setup` fills `PIXELFORGE_WEBHOOK_SECRET` with a random local value (PE-010) and `PIXELFORGE_DRIVER=fake` needs no key. Nothing is configured in the dashboard.
- **Local against the real Pixelforge:** use a **dev** key. Set `PIXELFORGE_DRIVER=http`, the dev key as `PIXELFORGE_API_KEY`, and, to receive webhooks, a tunnel plus the dev key's webhook URL and its `whsec_…` as `PIXELFORGE_WEBHOOK_SECRET` (see [Real webhooks locally](#real-webhooks-locally)), then `make restart-worker`. The dev key must never be the production key: `/v1/usage` and the webhook URL are per key, so dev traffic would land in production's usage report and production's webhooks would go to your tunnel. `make live-smoke` uses this dev key too.
- **Production**, in this order:
  1. **Dashboard.** Create a dedicated production key. Set its webhook URL to exactly `https://prompt.sergiupopa.dev/webhooks/pixelforge`: https, no trailing slash, nothing that redirects, because Pixelforge follows no redirect. Keep its `whsec_…` secret.
  2. **`shared/.env`.** From the domain folder, `grep -c '^PIXELFORGE_' shared/.env` must print 0; a `.env` made from the current template has the three names blank, so remove them first with `sed -i '/^PIXELFORGE_/d' shared/.env`. Then, from your machine, send the values over stdin, never argv:

     ```bash
     read -rs PF_KEY; read -rs PF_SECRET        # the production key, then its whsec_…; not echoed
     printf 'PIXELFORGE_DRIVER=http\nPIXELFORGE_API_KEY=%s\nPIXELFORGE_WEBHOOK_SECRET=%s\n' "$PF_KEY" "$PF_SECRET" \
       | ssh admin@164.90.236.77 'cat >> /home/admin/web/prompt.sergiupopa.dev/shared/.env'
     unset PF_KEY PF_SECRET
     ```

     `grep -c '^PIXELFORGE_' shared/.env` now prints 3, and `stat -c '%a %U:%G' shared/.env` prints `600 admin:admin`.
  3. **Deploy.** `dep deploy`, then wait until `/health/reconciler` reads `ok` (the scheduler's next minute; on the first go-live the heartbeat doesn't exist yet), then `make smoke-production`. The smoke also checks `/health/reconciler` and that an unsigned `POST /webhooks/pixelforge` answers 400 with no redirect, which proves the URL and that the secret is set.
  4. **Config**, as `admin` in `current/`. Never `config:show pixelforge`, which would print the key.

     ```bash
     php8.4 artisan tinker --execute 'var_dump(config("pixelforge.driver"), filled(config("pixelforge.api_key")), filled(config("pixelforge.webhook_secret")));'   # "http", true, true
     php8.4 artisan about --only=environment     # production, debug OFF
     php8.4 artisan tinker --execute 'echo implode(" ", [config("pixelforge.connect_timeout_seconds"), config("pixelforge.request_timeout_seconds"), config("pixelforge.submit.job_timeout_seconds"), config("queue.connections.database.retry_after"), config("pixelforge.submit.window_seconds")]);'   # 5 30 60 90 1800
     systemctl cat prompt-edit-worker            # ExecStart as in step 5 of the provisioning above
     ```

     The HTTP client's 30 s stays below the submit job's 60 s, which stays below the queue's `retry_after` of 90 s, so a job is never run twice at once.
  5. **Webhook.** `curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' -X POST https://prompt.sergiupopa.dev/webhooks/pixelforge` prints `400` and no redirect. Press **Send test event** in the dashboard: its delivery log shows a 2xx within 10 s, and `php8.4 artisan tinker --execute 'echo DB::table("pixelforge_webhook_events")->where("type", "ping")->count();'` is at least 1.
- **Changing the secret**, if it is ever regenerated in the dashboard: replace the line over stdin and redeploy straight away.

  ```bash
  read -rs PF_SECRET
  printf 'PIXELFORGE_WEBHOOK_SECRET=%s\n' "$PF_SECRET" | ssh admin@164.90.236.77 \
    'umask 077; f=/home/admin/web/prompt.sergiupopa.dev/shared/.env; { grep -v "^PIXELFORGE_WEBHOOK_SECRET=" "$f"; cat; } > "$f.new" && chmod 600 "$f.new" && mv "$f.new" "$f"'
  unset PF_SECRET
  dep deploy
  ```

  Deliveries signed with the old secret get a 400 in the gap, and Pixelforge retries each for up to 27 min 40 s (10 s, 30 s, 2 min, 10 min and 15 min). The reconciler catches any that give up.
- **How to check it works:** the dashboard's delivery log (every attempt and our status code), the failed-jobs listing (`php8.4 artisan queue:failed` in `current/`; `queue:retry <id>` or `queue:forget <id>` after reading one), and the reconciler's health check below.

#### Reconciler health

`GET /health/reconciler` answers `200 {"status":"ok","last_succeeded_at":"…Z"}` while the reconciler's last completed run is at most 5 min old, and `503` `stale` otherwise, including before the first run or for a minute after a `cache:clear` (D-59). It has no session or cookie and is never cached, so any uptime check can poll it. `/up` keeps meaning "the app boots".

```bash
curl -s -w ' %{http_code}\n' https://prompt.sergiupopa.dev/health/reconciler
```

#### Deploys and webhooks

A deploy's PHP-FPM reload, or a short outage of the droplet, can miss Pixelforge's 10 s webhook window. Pixelforge retries the delivery, and the reconciler polls any generation that hears nothing for 2–3 min, so a deploy never loses a status or a charge.

#### Go-live checks

Verified on production at go-live (PE-015) and worth repeating after any change to the server:

| Check | How |
| --- | --- |
| Production settings | `about --only=environment`: production, debug off. An unknown URL renders the plain 404 page; a 500 shows the generic page with no trace (`ProductionErrorPagesTest`) |
| No fake driver | The app refuses to boot with `PIXELFORGE_DRIVER=fake` in production (`RefusesFakePixelforgeInProductionTest`) |
| HTTPS | Plain http redirects to https, generated URLs are https, and the session cookie is `Secure` and `HttpOnly` (`make smoke-production`) |
| Abuse limits | 5 failed sign-ins a minute per email and IP, shown inline; 10 generations a minute per user (D-39); 3 new accounts an hour per IP (D-57). `php8.4 artisan tinker --execute 'echo config("generations.submissions_per_minute"), " ", config("auth.registrations_per_hour");'` prints `10 3`. In a browser, six wrong passwords within a minute for a throwaway email show "Too many login attempts. Please try again in N seconds." under Email on the 6th, never an error page. The account limit uses the same client IP as the sign-in throttle: repeat the [Client IP](#first-deploy) check |
| Expired session | `curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' -X POST -H 'X-Inertia: true' -H 'X-Requested-With: XMLHttpRequest' -H 'Referer: https://prompt.sergiupopa.dev/login' https://prompt.sergiupopa.dev/login` prints `303 https://prompt.sergiupopa.dev/login`: a request with no token is sent back with the notice (D-60), never a 419 page |
| Timeouts | The step 4 echo prints `5 30 60 90 1800` |
| Worker and scheduler | `systemctl is-active prompt-edit-worker` prints `active`; `/health/reconciler` is `ok`; as root, the last `CRON … (admin) CMD (cd /home/admin/web/prompt.sergiupopa.dev…` line in `/var/log/syslog` is within the last minute |
| Failed jobs | `php8.4 artisan queue:failed` lists none, or each is explained |
| No secrets in logs | From the domain folder, each prints `:0` for every file: `grep -c -F -f <(sed -n 's/^PIXELFORGE_API_KEY=//p' shared/.env) shared/storage/logs/laravel-*.log`, the same with `PIXELFORGE_WEBHOOK_SECRET`, and `grep -c -E 'whsec_\|Authorization\|Bearer ' shared/storage/logs/laravel-*.log`. As root, pipe `journalctl -u prompt-edit-worker --since today` through the same three |
| No arguments in traces | As root: `grep -rhsE 'zend\.exception_ignore_args' /etc/php/8.4/fpm/php.ini /etc/php/8.4/fpm/conf.d /etc/php/8.4/fpm/pool.d` shows `On`, and `php8.4 -r 'var_dump(ini_get("zend.exception_ignore_args"));'` prints `"1"` |
| Money on real generations | Note `date -u +%FT%TZ`, create generations with the [test keywords](#reviewer-guide), then `php8.4 artisan app:check-generation-money --since=<that instant>`: every row `OK`, exit 0. It prints the keywords, never the prompt |

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
- `make live-smoke` — opt-in PHPUnit `live` group against the real Pixelforge, with the dev key from `.env` (D-58); never part of `make test`, `make check` or CI
- `make smoke-production` — opt-in Playwright smoke against production (needs `E2E_BASE_URL`, `E2E_REVIEWER_EMAIL`, `E2E_REVIEWER_PASSWORD`); never part of `make e2e` or CI

## Testing

| Layer | Tool | Location | Run |
| --- | --- | --- | --- |
| PHP unit and feature (HTTP, Inertia props, jobs) | PHPUnit against `test-postgres` | `tests/Unit`, `tests/Feature` | `make test` |
| JS unit — pure `resources/js/lib` functions and composables | Vitest (`unit` project, node) | `tests/js/unit` | `make js-test` |
| JS integration — mounted components and pages, Inertia mocked | Vitest + Vue Test Utils (`integration` project, jsdom) | `tests/js/integration` | `make js-test` |
| End-to-end — critical user flows in a real browser | Playwright (`playwright` Compose service) | `tests/e2e` | `make e2e` |
| Live Pixelforge smoke — opt-in, dev key only, never in CI | PHPUnit group `live` | `tests/Feature/Pixelforge/Live` | `make live-smoke` |
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

- `.docker/` — Dockerfile (multi-stage: development and ci; production deploys with Deployer), entrypoint, Apache/PHP/supervisor config.
- `.agent-workflow/` — requirements analysis, story backlog, agent configuration and the prompt log (see *How this is built*).
- `CLAUDE.md` / `AGENTS.md` — guidelines for AI coding agents; Laravel Boost rules and the Docker command convention.
- `boost.json` — Laravel Boost configuration.
- `phpstan.neon`, `pint.json`, `phpunit.xml` — quality-tool configuration.

## License

MIT.
