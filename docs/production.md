# Production

Live at **https://prompt.sergiupopa.dev**, deployed with [Deployer](https://deployer.org) (`deploy.php`, D-19) on the HestiaCP droplet `164.90.236.77`, user `admin`, in `/home/admin/web/prompt.sergiupopa.dev`.

- **Web:** Hestia's nginx (Let's Encrypt, forced HTTPS) → Apache with the `deployer` template (DocumentRoot `current/public`) → PHP 8.4-FPM running as `admin` (backend `Symfony-PHP-8_4`, whose `open_basedir` is the whole domain folder). Apache sees `HTTPS=on` and mod_remoteip gives PHP the visitor's IP, so Laravel trusts no proxy (D-21). The chain does pass a client's `X-Forwarded-Host` on to PHP as the Host, so the app answers only for its own host and its subdomains (`trustHosts()`), and any other host gets a 400.
- **Queue worker:** the systemd unit `prompt-edit-worker` (`queue:work database`, `WorkingDirectory` = `current`).
- **Scheduler:** a Hestia cron job for `admin` runs `schedule:run` every minute. It runs the reconciler (`app:reconcile-generations`, every minute on one server, D-54) and the nightly backup, so PE-014 needed no droplet change.
- **Database:** PostgreSQL 17 (PGDG) on localhost:5432, database and role `prompt_edit`. Queue, cache and sessions use it too; there is no Redis (D-20).
- The droplet is shared with yum and azsemaus. Their domains, PHP pools, MariaDB and Redis are untouched.

## One-time provisioning (root)

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

## Reviewer account

`app:upsert-user` creates or updates a user by email. The password is read from the first line of stdin, never from an argument, so it isn't visible in `ps` on the shared droplet or saved in the server's shell history (D-23). Run on a terminal without a pipe, it refuses rather than echo the password as you type it. From your machine:

```bash
read -rs E2E_REVIEWER_PASSWORD; export E2E_REVIEWER_PASSWORD   # not echoed, not in history
export E2E_REVIEWER_EMAIL=reviewer@example.com                   # the reviewer's address
printf '%s\n' "$E2E_REVIEWER_PASSWORD" | ssh admin@164.90.236.77 \
  "cd /home/admin/web/prompt.sergiupopa.dev/current && php8.4 artisan app:upsert-user '$E2E_REVIEWER_EMAIL' Reviewer"
```

The password follows the registration rules (at least 8 characters). It lives only in your shell session and your password manager, never in a remote command line, the server's history or the app log, which records only the email and user id. To rotate it, run the same command with a new password. Existing sessions stay valid until they expire (120 minutes).

## Operator access

`app:grant-operator` makes an existing account an operator, who sees Report in the nav and may open the operator report (D-62). Create the account first with `app:upsert-user` (above), then, from your machine:

```bash
ssh admin@164.90.236.77 \
  "cd /home/admin/web/prompt.sergiupopa.dev/current && php8.4 artisan app:grant-operator '$E2E_REVIEWER_EMAIL'"
```

It is idempotent: running it again prints "… is already an operator." and exits 0. It never creates users or sets passwords, and an unknown email exits 1 without writing anything. There is no UI for operator access and no revoke.

## First deploy

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

## Deploy runbook

1. Merge to `main` and wait for CI to pass. `dep deploy` refuses to deploy unless the latest CI run on `origin/main`'s head succeeded, and it deploys exactly that commit. The gate runs locally and needs an authenticated `gh`.
2. `dep deploy`. It installs Composer and npm dependencies, builds the assets (through nvm, then removes `node_modules`), runs `migrate --force` and the caches, then `app:grant-opening-credits` so no account is served a balance without its grant (safe to re-run), flips `current`, reloads PHP-FPM (otherwise OPcache keeps serving the old release) and restarts the queue worker (`artisan reload`).
3. `make smoke-production`.

**Every merged story is redeployed and smoke-checked.** Rollback is `dep rollback`, which also reloads PHP-FPM and restarts the worker. It doesn't roll migrations back, so migrations are forward-only and must stay compatible with the previous release.

The smoke runs Playwright in the `playwright` service (`make up` first) against production. It checks `/up`, `/health/reconciler`, that the webhook URL answers an unsigned delivery with a 400 and no redirect, the http → https redirect, a plain 404, that a spoofed `X-Forwarded-Host` never reaches a URL, the sign-in page and its cookie flags, no mixed content and no console errors, then signs the reviewer in, checks History and the operator report read-only, and signs out (D-76). History must show a succeeded generation with its charge and a loaded image, so that check needs an image less than 24 h old (see [Demo refresh and sign-off](#demo-refresh-and-sign-off)); the report must show every day and the total as Match against `/v1/usage`, reloading for up to 3 min. Its only writes are sign-in and sign-out. It never registers users.

```bash
export E2E_BASE_URL=https://prompt.sergiupopa.dev   # plus E2E_REVIEWER_EMAIL and E2E_REVIEWER_PASSWORD, as above
make smoke-production
```

## Pixelforge on production

Production runs the real API with its **own dedicated key**. The webhook URL, the signing secret and `/v1/usage` are all per key, so production's usage report only ever counts production's generations. Rate limits are per account, so every key shares the 60 requests a minute, 3 in flight and 500 a day.

### Per-environment runbook

Variable names only: no key or secret is ever committed, echoed, logged or put on a command line.

- **Local with the fake driver** (the default): `make setup` fills `PIXELFORGE_WEBHOOK_SECRET` with a random local value (PE-010) and `PIXELFORGE_DRIVER=fake` needs no key. Nothing is configured in the dashboard.
- **Local against the real Pixelforge:** use a **dev** key. Set `PIXELFORGE_DRIVER=http`, the dev key as `PIXELFORGE_API_KEY`, and, to receive webhooks, a tunnel plus the dev key's webhook URL and its `whsec_…` as `PIXELFORGE_WEBHOOK_SECRET` (see [Real webhooks locally](development.md#real-webhooks-locally)), then `make restart-worker`. The dev key must never be the production key: `/v1/usage` and the webhook URL are per key, so dev traffic would land in production's usage report and production's webhooks would go to your tunnel. `make live-smoke` uses this dev key too.
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

### Reconciler health

`GET /health/reconciler` answers `200 {"status":"ok","last_succeeded_at":"…Z"}` while the reconciler's last completed run is at most 5 min old, and `503` `stale` otherwise, including before the first run or for a minute after a `cache:clear` (D-59). It has no session or cookie and is never cached, so any uptime check can poll it. `/up` keeps meaning "the app boots".

```bash
curl -s -w ' %{http_code}\n' https://prompt.sergiupopa.dev/health/reconciler
```

### Deploys and webhooks

A deploy's PHP-FPM reload, or a short outage of the droplet, can miss Pixelforge's 10 s webhook window. Pixelforge retries the delivery, and the reconciler polls any generation that hears nothing for 2–3 min, so a deploy never loses a status or a charge.

### Go-live checks

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
| Money on real generations | Note `date -u +%FT%TZ`, create generations with the [test keywords](reviewer-guide.md), then `php8.4 artisan app:check-generation-money --since=<that instant>`: every row `OK`, exit 0. It prints the keywords, never the prompt |

### Demo refresh and sign-off

Shortly before hand-in, within 24 h so the images are still live, after `dep deploy` of the current `main` and a first `make smoke-production` (PE-018). No password ever goes on a command line: `E2E_REVIEWER_PASSWORD` comes from `read -rs`, as in [Reviewer account](#reviewer-account).

1. **Operator.** `ssh admin@164.90.236.77 "cd /home/admin/web/prompt.sergiupopa.dev/current && php8.4 artisan app:grant-operator '$E2E_REVIEWER_EMAIL'"` prints "… is already an operator." and exits 0.
2. **Balance ([PE-017](../.agent-workflow/stories/PE-017-credit-balances-buy-credits/story.md)).** Signed in as the reviewer, an available balance must be at least $0.7119, the demo set's holds (711,850,000 n$). If it is lower, buy one credits pack first.
3. **Note the instant.** `export SINCE=$(date -u +%FT%TZ)`.
4. **Demo set.** As the reviewer, on Generate, submit PE-015's 8 rows, each prompt at most 40 code points: `[succeed] a lighthouse at dawn` (Square, Low); `[succeed] a red fox in fresh snow` (Landscape, Medium, refs `https://example.com/ref-1.png` and `https://example.com/ref-2.png`); `[succeed] a mountain lake at noon` (Portrait, High); then `[fail] a broken pocket watch`, `[blocked] a forbidden scene`, `[overloaded] a busy harbour`, `[succeed] [duplicate] two moons` and `[succeed] [slow] a slow sunrise`, each Square, Low, no refs.
5. **Each status and one charge.** The three `[succeed]` rows succeed with an image and a charge; `[fail]` fails and `[blocked]` is rejected, both "Not charged"; `[overloaded]` shows the retry note, then fails after about 2 min, "Not charged"; `[duplicate]` succeeds, with two deliveries per event on the dashboard; `[slow]` processes for 60–90 s, then succeeds without a reload. Then `ssh admin@164.90.236.77 "cd /home/admin/web/prompt.sergiupopa.dev/current && php8.4 artisan app:check-generation-money --since=$SINCE"` prints 8 rows, every check `OK`, exit 0, with at most 516,950,000 n$ of provider cost and 646,187,500 n$ charged.
6. **Report parity.** At least 60 s after the last generation is terminal, Report's default range shows no notice, every day Match (today In progress and Match) and Total Match; hovering a "Pixelforge says" cost shows the same 9 decimals as "We recorded".
7. **Final smoke.** `make smoke-production` passes, History's image check included. The results go into a PR comment as a ticked list, with dates and no credentials.

## Logs

- Laravel: `shared/storage/logs/laravel-YYYY-MM-DD.log`, one file per day, kept 14 days, at level `info`. Lines at `error` and above also reach Sentry (below).
  ```bash
  tail -f /home/admin/web/prompt.sergiupopa.dev/shared/storage/logs/laravel-$(date +%F).log
  ```
- Apache and nginx: `/var/log/apache2/domains/prompt.sergiupopa.dev.*` and `/var/log/nginx/domains/`.
- Queue worker: `journalctl -u prompt-edit-worker` (as root).

## Error monitoring

Production reports to Sentry (D-77): every exception Laravel reports, and every log line at `error` and above through the `sentry` channel of `LOG_STACK=daily,sentry`. The daily file still gets every line at `info`. Without a DSN nothing is sent, which is the case everywhere but production.

- **The DSN** goes in `shared/.env` only, as `SENTRY_LARAVEL_DSN`, written in place on the server like the other secrets: never committed, never on a command line. The same file needs `LOG_CHANNEL=stack` and `LOG_STACK=daily,sentry` (`.env.production.example`). The next `dep deploy` caches the config, reloads PHP-FPM and restarts the worker, so every process picks the change up.
- **After that deploy**, as `admin` in `current/`:
  ```bash
  php8.4 artisan about --only=sentry   # Enabled YES, Environment production, Data Collection ENABLED, Sample Rate Errors 100%, Performance and Profiling NOT SET
  php8.4 artisan sentry:test           # "Test event sent with ID: …"; the event shows in Sentry under production
  ```
  `sentry:test` sends a synthetic exception from the command itself, through its own client, so it carries no user data.
- **Never sent:** request and response bodies (sign-in passwords, prompts, Pixelforge's answers), cookies, any user (no id, email or IP is attached), the headers that carry the visitor's IP, SQL bindings, queue job arguments, function arguments in stack traces, and cache keys (the sign-in throttle's is `email|ip`).
- **Never sent to Pixelforge:** Sentry's `baggage` and `sentry-trace` headers. The package would add them to every request even with tracing off, and the baggage names the project's public key and organisation.
- **Filtered from every string before sending** (`RedactSentryEvent`): the Pixelforge API key and webhook secret, the app key and database password, any `whsec_…`, bearer and Authorization values, email addresses, values under keys such as `password`, `prompt` or `…_token`, an Artisan command line's arguments (`app:upsert-user` takes a display name), and a failed query's bindings and PostgreSQL DETAIL values.
- **Optional settings**, all off by default: `SENTRY_RELEASE` names the release, `SENTRY_TRACES_SAMPLE_RATE` and `SENTRY_PROFILES_SAMPLE_RATE` (0 to 1) turn tracing and profiling on, and `LOG_SENTRY_LEVEL` moves the channel's level from `error`.

## Backups and restore

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
