# Plan — PE-004-walking-skeleton-own-server

## Approach
The only new app code is two operator commands: one upserts a user by email with the password read from stdin, and one is a nightly `pg_dump` run by the scheduler. Everything else is deployment: `deploy.php` with a local CI gate, `.env.production.example`, an opt-in Playwright production smoke and the README runbook. Laravel trusts no proxy, because Hestia's Apache already presents `HTTPS=on` and the visitor's address natively, and feature tests pin that choice and the clean boot without Pixelforge (see "The AC10 choice" below).

## Tasks
Each task is one TDD commit: write the failing test first, then the code, and run `make check` green before committing. Tasks 1 and 2 pin behaviour that already holds, so no app code should change in them. Tasks 6–8 can't run against the server from the pipeline. Their checks are `make lint` (Pint also formats `deploy.php`), `make js-check` and a `--list` dry run of the smoke. The orchestrator's first deploy and QA's smoke run are the real verification.

- [ ] 1. Pin AC10 in `HttpsBehindHestiaProxyTest` (rows under Tests) and record D-21. No app code should change. Commit: `[PE-004] Pin HTTPS and the client IP behind Hestia's proxy chain`
- [ ] 2. Pin AC14 in `BootsWithoutPixelforgeTest`, with the env-stripping `createApplication()` override described under Tests. The precondition row fails first locally, because the developer's `.env` already holds `PIXELFORGE_API_KEY`. No app code should change. Commit: `[PE-004] Prove every page answers without Pixelforge configuration`
- [ ] 3. Add `UpsertUserCommand`, test-first in `UpsertUserCommandTest` (AC16), and record D-23. Commit: `[PE-004] Add a command that creates or updates a user by email`
- [ ] 4. Add `BackupDatabaseCommand`, the `database.backups.path` config key and its schedule entry in `routes/console.php`, test-first in `BackupDatabaseCommandTest` (AC8), and record D-22. Commit: `[PE-004] Back up the database nightly with pg_dump`
- [ ] 5. Add `.env.production.example`, test-first in `ProductionEnvTemplateTest` (AC5, 11–15, 19). Commit: `[PE-004] Add the production environment template`
- [ ] 6. Add `deploy.php` as described under Deployment. Commit: `[PE-004] Deploy to prompt.sergiupopa.dev with Deployer`
- [ ] 7. Add `playwright.production.config.ts`, `tests/smoke/production.spec.ts` and the `smoke-production` Makefile target. Add `tests/smoke` to the `format` / `format:check` scripts and both new paths to `tsconfig.json` `include`. Check that `npx playwright test --config=playwright.production.config.ts --list` (in the `playwright` service, with dummy `https://` env values) lists the suite, and that the default config still lists only `tests/e2e`. Commit: `[PE-004] Add an opt-in Playwright smoke check for production`
- [ ] 8. Write the README deployment section (outline under README), update the Status row, and add `make smoke-production` to Make Targets and Testing. Commit: `[PE-004] Document the production deployment and runbook`

## The AC10 choice: no trusted proxies
- **Facts (orchestrator, 2026-11-19).** Hestia's nginx on :443 proxies to Apache on `https://164.90.236.77:8443`, so Apache and PHP-FPM see `HTTPS=on` natively. nginx sets `X-Real-IP $remote_addr`. Apache's mod_remoteip (`RemoteIPHeader X-Real-IP`, internal proxies `127.0.0.1` and `164.90.236.77`) rewrites `REMOTE_ADDR` to the visitor's IP before PHP sees it.
- **Choice.** `bootstrap/app.php` gets no `trustProxies` call. Laravel's default `TrustProxies` middleware then trusts nobody. `request()->isSecure()` follows `HTTPS`, and `request()->ip()` is `REMOTE_ADDR`, which is already the visitor. yum runs the same way.
- **Why it is the narrowest.** The story allowed trusting loopback. That would add nothing, because after mod_remoteip `REMOTE_ADDR` is never loopback. It would also widen trust if mod_remoteip were ever removed. `URL::forceScheme('https')` is rejected too: it would hide a broken proxy hop instead of letting the smoke's mixed-content check catch it.
- **The adapted AC10 test.** The story's "a trusted proxy's `X-Forwarded-Proto` gets https" row becomes two rows. `HTTPS=on` gives `https://` URLs. `X-Forwarded-*` and `Forwarded` are ignored from every address, loopback and the server IP included. A third row proves the sign-in throttle key uses `REMOTE_ADDR`.

## Story clarifications
- **AC13's "AC14 info line"** is a numbering leftover from the Laravel Cloud version of this story (commit c9f0f59). There it was the account command's info line ("User … created/updated", never the password), which is now AC16. `UpsertUserCommand` logs it at `info`, and `LOG_LEVEL=info` keeps it.
- **AC2's `queue:restart`** comes from the recipe. Deployer 8.0.5's `recipe/laravel.php` ends `deploy` with `artisan:reload` (Laravel ≥ 12.41), which runs `queue:restart` and `schedule:interrupt` after `deploy:publish`. `deploy.php` adds no second restart; a comment says why.
- **AC8 is adjusted (orchestrator):** the scheduler runs the dump, so there is no second cron.
- **AC12:** a 404 can't prove debug is off, because Laravel renders HTTP exceptions with the plain error view in debug mode too. The proof is `php8.4 artisan about` over SSH (QA), plus `APP_DEBUG=false` pinned in the template test. The smoke still asserts that the 404 is plain.

## Backend
PHP classes are `final` and declare `strict_types`. Commands use the `#[Signature]` / `#[Description]` attributes (Laravel 13; yum's `SetFamilyPinCommand` does the same) and live in `app/Console/Commands/`, which Laravel auto-registers.

| Class / file | Location | Role |
| --- | --- | --- |
| `UpsertUserCommand` | `app/Console/Commands/` | `app:upsert-user {email} {name}`. The password is the first line of standard input. It reads the command input's stream when one is set (`StreamableInputInterface::getStream()`, which is how tests feed it), else `STDIN`. It strips one trailing `\n` or `\r\n` and nothing else, since registration's `TrimStrings` leaves passwords alone too. Name and email are trimmed, and the email is `Str::lower`ed (D-18). Validation matches registration minus `confirmed` and `unique`: name required, string, max 255; email required, string, email, max 255; password required, string, `Password::default()`. On failure it prints each message (Laravel's messages never echo the value), writes nothing and returns `FAILURE`. On success it calls `User::query()->updateOrCreate` keyed by email (the model's `hashed` cast hashes the password), prints "Created user …" or "Updated user …" (from `wasRecentlyCreated`), and logs one `info` line with the email and user id. It returns `SUCCESS`. Parameters that carry the password are `#[SensitiveParameter]`. No factory and no Faker, since both are dev-only and absent with `--no-dev`. |
| `BackupDatabaseCommand` | `app/Console/Commands/` | `app:backup-database`. If `database.backups.path` is blank, it prints "Set DB_BACKUP_PATH …" and returns `FAILURE` without running anything. Otherwise it reads host, port, database, username and password from the default connection's config (pgsql). It ensures the directory exists (0700). It runs `pg_dump --format=custom --no-password` through the `Process` facade, with an array command, the password only as `PGPASSWORD` in the process env, and a 300 s timeout. The output goes to `<path>/<database>-<weekday>.dump.partial`, where weekday is lowercase English `now()->format('l')`, giving 7 rotating names. On success it renames the partial over `<database>-<weekday>.dump` (so a failed dump never destroys last week's copy), prints the file path and logs `info` with path and size. On failure it deletes the partial, logs `error` with the exit code and pg_dump's stderr, and returns `FAILURE`. The password is never in the command line, the output or a log context. |
| `config/database.php` | | New top-level `backups.path` key = `env('DB_BACKUP_PATH')`, null by default, so local and CI have no backup path. |
| `routes/console.php` | | Adds a schedule entry: `Schedule::command(BackupDatabaseCommand::class)->dailyAt('04:30')->when(…)`, where the closure is true only when `database.backups.path` is filled. Local `schedule:work` and CI's scheduler container therefore never run it. 04:30 is in the app timezone (UTC), before Hestia's 05:10 user backup (see Risks). |
| `.env.production.example` | repo root | Exactly the orchestrator's `shared/.env` names, in the listed order, with yum-style one-line comments. Non-secret values are filled in. `APP_KEY` and `DB_PASSWORD` are blank, with a comment on how each is generated on the server. One extra name: `DB_BACKUP_PATH=/home/admin/web/prompt.sergiupopa.dev/private/backups`. **The orchestrator must add it to `shared/.env`.** No `PIXELFORGE_*` names (AC14; PE-015 adds them). |

There is no Action class for the upsert. No domain in the map owns accounts yet. The write is one `updateOrCreate`, the same call `DemoUserSeeder` makes, and its only consumer is the command. An Action would only be a wrapper. Validation stays in the command, which is its input boundary, like a Form Request.

## Deployment
`deploy.php` at the repo root follows `../yum/deploy.php` line for line where it applies. It uses the global Deployer 8.0.5, so `composer.json` doesn't change. Every claim below about the recipe was checked against the 8.0.5 phar source.

| Setting / task | Role |
| --- | --- |
| Header | `namespace Deployer;` and `require 'recipe/laravel.php'`. |
| Settings | `application` `prompt.sergiupopa.dev`, `repository` `git@github.com:sergiu-popa/prompt-edit.git`, `branch` `main`, `keep_releases` 5. `bin/php` `/usr/bin/php8.4`; `bin/composer` `/usr/bin/php8.4 /usr/local/bin/composer`. `writable_dirs` [] (the FPM pool runs as admin), with yum's comment. The recipe defaults stay: `shared_files` `.env`, `shared_dirs` `storage`. There is no `auth.json` (no private packages) and no `git_tty` (deploys run non-interactively, and github.com is already in admin's `known_hosts` from yum). |
| Host | `164.90.236.77`, remote user `admin`, `~/.ssh/id_rsa`, port 22, `deploy_path` `/home/admin/web/prompt.sergiupopa.dev`, `php_fpm_service` `php8.4-fpm`. A comment block lists the topology: deployer template, `Symfony-PHP-8_4` backend, the systemd worker, the Hestia cron and Postgres 17. |
| `deploy:ci-gate` | `->once()`, only `runLocally()`, hooked `before('deploy', …)` so it runs before anything connects to the server. Steps: resolve `get('target')`; find its head with `git ls-remote <repository> refs/heads/<target>`, refusing when the branch isn't on origin (tags and revisions are refused too); run `gh run list --repo sergiu-popa/prompt-edit --workflow ci.yml --commit <sha> --limit 1 --json status,conclusion,url`. Each refusal throws `error()` with a clear message: no CI run yet, still `<status>`, or concluded `<conclusion>`, each with the sha and run URL. A missing `gh` gets its own message, via `testLocally('command -v gh')`. On a pass it prints `info` and pins the target with `set('target', <sha>)`. Deployer's master persists host config between tasks, so `deploy:update_code` archives exactly the commit CI approved. |
| `deploy:npm:install` / `deploy:npm:build` | As in yum: `source $HOME/.nvm/nvm.sh`, then `npm ci --no-audit --no-fund`; `npm run build && rm -rf node_modules`. Hooked after `deploy:vendors`. |
| `deploy:php-fpm:reload` | yum's guarded `sudo -n /usr/bin/systemctl reload php8.4-fpm`, with its OPcache comment and the warning fallback. Hooked `after('deploy:symlink', …)` **and** `after('rollback', …)`. `rollback` flips `current` too, so it has the same OPcache problem. |
| Worker restart | No new task on deploy (the recipe's `artisan:reload` handles it, see Story clarifications). Add `after('rollback', 'artisan:queue:restart')`, so a rollback also moves the worker onto the restored release. |
| Failure | `after('deploy:failed', 'deploy:unlock')`. |

The first deploy is from the story branch: `dep deploy --branch=pe-004-walking-skeleton-own-server`. `--branch` is a `VALUE_REQUIRED` option in 8.0.5's `update_code.php` and overrides the `branch` setting. The gate checks that branch's head. From then on it is plain `dep deploy` (main).

## Production smoke
| File | Role |
| --- | --- |
| `playwright.production.config.ts` | `testDir` `tests/smoke`, so CI's `npx playwright test` and `make e2e` (default config, `tests/e2e`) never pick it up. It throws at load unless `E2E_BASE_URL` starts with `https://` and `E2E_REVIEWER_EMAIL` and `E2E_REVIEWER_PASSWORD` are set; this also stops the compose default `http://127.0.0.1:8080` from being used. One `production` project (Desktop Chrome), `workers` 1, `retries` 0, `forbidOnly`, `list` reporter. `trace` and `video` are off, because a trace records `fill()` values and would store the reviewer password. `screenshot` is `only-on-failure` (the password field shows dots). |
| `tests/smoke/production.spec.ts` | Imports `test` / `expect` from `../e2e/fixtures`, so any console or page error fails it (AC17). Rows are under Tests. It never registers a user and writes nothing beyond one session. |
| `Makefile` `smoke-production` | Fails with a clear message, before Docker starts, if any of the three variables is unset. Then it runs `docker compose run --rm -e E2E_BASE_URL -e E2E_REVIEWER_EMAIL -e E2E_REVIEWER_PASSWORD playwright npx playwright test --config=playwright.production.config.ts`. `-e NAME` without a value passes the shell's value, so the password never appears on a command line. Like `make e2e`, it needs `make up`, because the service shares the app container's network. |

## README
A new **Production** section replaces nothing in the existing sections, plus small edits: the Status row, Make Targets (`make smoke-production`) and a Testing row ("Production smoke, opt-in, never in CI"). The section has these parts:

- **Overview.** The live URL `https://prompt.sergiupopa.dev`, and the topology: Hestia's nginx → Apache (deployer template, DocumentRoot `current/public`) → php8.4-fpm as `admin` (`Symfony-PHP-8_4`, `open_basedir` = the domain folder), with Let's Encrypt and forced HTTPS. Also the systemd worker, the Hestia cron scheduler, Postgres 17 on localhost, and the database queue, cache and sessions (D-20). The droplet is shared with yum and azsemaus, whose domains, pools, MariaDB and Redis are untouched.
- **One-time provisioning (root).** These are copy-pasteable commands matching the server facts; the orchestrator reconciles them with what was run before QA.
  - Hestia: `v-change-web-domain-tpl admin prompt.sergiupopa.dev deployer` and `v-change-web-domain-backend-tpl … Symfony-PHP-8_4`, then `v-add-letsencrypt-domain` and `v-add-web-domain-ssl-force`. Then `chmod u+w` on the domain folder (or Deployer can't create `releases/`, as in yum).
  - `install -d -m 700 -o admin -g admin` for `private/backups`.
  - GitHub read access check as `admin`: `git ls-remote` of the repository.
  - PGDG: `postgresql-common`, `/usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y`, `postgresql-17`.
  - The `conf.d` drop-in: `/etc/postgresql/17/main/conf.d/`, with `listen_addresses = 'localhost'` and the small memory settings. Then a restart, and a `ss -ltn` check that 5432 listens on 127.0.0.1 only.
  - The role and database: the password is generated into a shell variable without echoing it. psql runs with `-v ON_ERROR_STOP=1 -v pw=…` and SQL fed on stdin using `:'pw'`, because `psql -c` doesn't interpolate variables. The `prompt_edit` role (LOGIN, its own password) owns the `prompt_edit` database.
  - `shared/.env`: created from `.env.production.example`, with `APP_KEY` and `DB_PASSWORD` written in place without echoing, `chown admin:admin` and `chmod 600`.
  - The systemd unit, written with a heredoc, exactly as in the server facts, then `systemctl daemon-reload` and `systemctl enable prompt-edit-worker`. It is started after the first deploy.
  - Sudoers: a `/etc/sudoers.d/` file with the worker line (start, stop, restart only), `chmod 440` and `visudo -cf`. The FPM reload line already exists from yum.
  - The scheduler: `v-add-cron-job admin` every minute, with the facts' command (Hestia overwrites a hand-edited crontab). The backups need no cron: the scheduler runs `app:backup-database` at 04:30 UTC.
- **Reviewer account.** Read `E2E_REVIEWER_PASSWORD` with `read -rs` (no history), then pipe it over SSH: `printf '%s\n' "$E2E_REVIEWER_PASSWORD" | ssh admin@… 'cd …/current && php8.4 artisan app:upsert-user "$email" "Reviewer"'`. It persists only in the operator's shell session or password manager. It is never in a remote command line, the server's history or the app log. To rotate it, re-run with a new password; existing sessions last until they expire (120 min).
- **First deploy.** `dep deploy --branch=pe-004-walking-skeleton-own-server` (the gate needs green CI on that head), then `systemctl start prompt-edit-worker`, the reviewer account and `make smoke-production`. Then these checks, run in `current/`: `migrate:status`, `about` (production, debug off, database drivers), `schedule:list` (the backup entry), `Artisan::queue('inspire')` via `tinker --execute` followed by `journalctl -u prompt-edit-worker` and an empty `jobs` table, and `systemctl status prompt-edit-worker`. The client-IP check: one failed sign-in with a throwaway email, then the `cache` table key `…<email>|<ip>` shows the operator's public IP. Never fix a wrong IP with `trustProxies(at: '*')`.
- **Deploy runbook.** CI green on `main` (the gate enforces it), then `dep deploy`, then `make smoke-production`. Rollback is `dep rollback`, which also reloads FPM and restarts the worker. Migrations are forward-only and must stay compatible with the previous release, because `dep rollback` doesn't roll them back. Every merged story is redeployed and smoke-checked.
- **Pixelforge on production.** Production needs its own key, because the webhook URL, signing secret and `/v1/usage` are all per key; PE-015 sets it up. Until PE-015 there are no `PIXELFORGE_*` variables in `shared/.env`. **Don't submit generations on production before PE-015.**
- **Logs.** `shared/storage/logs/laravel-YYYY-MM-DD.log`, kept 14 days at `info`, followed with `tail -f`. Apache and nginx logs are in `/var/log/{apache2,nginx}/domains/`, and the worker's in `journalctl -u prompt-edit-worker`.
- **Backups and restore.** Seven weekday dumps live in `private/backups/`, and Hestia's 05:10 backup of `admin` carries them (one copy on the same disk; off-droplet backups are out of scope, as yum's README notes). Restore steps:
  - stop the worker;
  - run `pg_restore --clean --if-exists --no-owner --single-transaction` as `prompt_edit` on 127.0.0.1 (password from `shared/.env`);
  - start the worker;
  - for older copies, extract the dump from `/backup/admin.<date>.tar` (`v-list-user-backups admin`).

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-21 | No trusted proxies in production. Hestia's nginx → Apache (HTTPS vhost, mod_remoteip on `X-Real-IP`) gives PHP `HTTPS=on` and the visitor's `REMOTE_ADDR`, so Laravel ignores every forwarded header | It is the narrowest setting. A client can't spoof the scheme, the host or the IP the sign-in throttle keys on. `trustProxies(at: '*')` would let any visitor choose their throttle bucket |
| D-22 | The nightly backup is a scheduled artisan command (`pg_dump -Fc` at 04:30 UTC into `private/backups/`, seven weekday files, credentials via `PGPASSWORD`), not a second cron | It is one schedule to maintain, and it runs before Hestia's 05:10 backup, which carries it. It is testable with `Process::fake()`, keeps the password off argv and out of the logs, and runs only where `DB_BACKUP_PATH` is set |
| D-23 | The account command reads the password from stdin | On a shared droplet, argv is readable by every local user (`ps`, `/proc`), and an option would also land in the server's shell history. The smoke's `E2E_REVIEWER_PASSWORD` pipes straight in |

## Tests
| Test | Location | Covers |
| --- | --- | --- |
| `HttpsBehindHestiaProxyTest` | `tests/Feature/Production/` | AC10, in the rows below. It uses `InteractsWithAuthentication` and `RefreshDatabase`. |
| `BootsWithoutPixelforgeTest` | `tests/Feature/Production/` | AC14 |
| `UpsertUserCommandTest` | `tests/Feature/Console/` | AC16 |
| `BackupDatabaseCommandTest` | `tests/Feature/Console/` | AC8 and D-22 |
| `ProductionEnvTemplateTest` | `tests/Unit/` | AC5, 11–15 and 19 at the template level. It parses `.env.production.example` with `Dotenv\Dotenv::parse()`: no app, no database. |
| `production.spec.ts` | `tests/smoke/` | AC1, 9, 10 (browser half), 11, 12 and 17, against production only. |

How the harnesses work:
- **HTTPS rows** request absolute `https://prompt.sergiupopa.dev/…` URLs. Symfony's `Request::create()` turns that scheme into exactly `HTTPS=on` plus port 443, which is what Apache hands PHP-FPM. Trap: `withServerVariables(['HTTPS' => 'on'])` on a relative path is undone, because the test's http `APP_URL` makes `Request::create()` unset `HTTPS`.
- **`BootsWithoutPixelforgeTest`** overrides `createApplication()`. It requires `bootstrap/app.php` and registers `afterBootstrapping(LoadEnvironmentVariables::class, …)`, which removes every `PIXELFORGE_*` name from `$_ENV`, `$_SERVER` and `putenv` after `.env` is read and before config is built. Then it bootstraps the kernel. `tearDown` restores the removed values. This matters because Dotenv writes `.env` into the process env on the first boot, so later tests could otherwise inherit or lose them (PE-009/010 may add values to `phpunit.xml`). The harness is future-proof whatever config keys PE-009 picks.
- **`UpsertUserCommandTest`** drives the command through Symfony's `CommandTester`, built from `Artisan::all()['app:upsert-user']`. `setInputs([...])` becomes the input stream; it always appends `PHP_EOL`, and `setInputs([])` gives empty stdin. Logs are captured by listening for `MessageLogged`.
- **`BackupDatabaseCommandTest`** sets a distinctive `database.connections.pgsql.password`, so "never logged" can't pass by accident. The backup path is a temp dir that doesn't exist yet, removed in `tearDown`. `Process::fake()` uses a callback that writes the `.partial` file for successful runs. The test uses no `RefreshDatabase`.

| Subject | Row | Expect |
| --- | --- | --- |
| HTTPS | Guest GET `https://…/dashboard` | Redirect to exactly `https://prompt.sergiupopa.dev/login` |
| HTTPS | Control: the same over `http://` | `http://…/login` (the scheme follows `HTTPS`, so the row above can fail) |
| HTTPS | Sign in with POST `https://…/login` | Redirect to `https://…/dashboard` |
| Spoofing | Data provider with `REMOTE_ADDR` `127.0.0.1` (nginx's loopback hop) and `164.90.236.77` (the public-IP hop). GET `http://…/dashboard` with `X-Forwarded-Proto: https`, `X-Forwarded-Host: attacker.example`, `X-Forwarded-Port: 443`, `X-Forwarded-For: 203.0.113.9` and `Forwarded: for=203.0.113.9;proto=https;host=attacker.example` | Redirect to exactly `http://prompt.sergiupopa.dev/login`; `request()->ip()` is the `REMOTE_ADDR` |
| Throttle | 5 failures from `REMOTE_ADDR` `198.51.100.7`, each with a different spoofed `X-Forwarded-For`, then the correct password from the same address (`freezeTime`) | Refused with the 60-second lockout message: one bucket, keyed on `REMOTE_ADDR` |
| Throttle | Then the correct password from `198.51.100.8` carrying `X-Forwarded-For: 198.51.100.7` | Signs in (the forwarded IP is not the key) |
| Boot | Precondition | No `PIXELFORGE_*` name in `getenv()`, `$_ENV` or `$_SERVER` inside the test. This fails first locally |
| Boot | Data provider: guest `/`, `/login`, `/register`, `/up` | 200 each |
| Boot | Signed-in `/dashboard` | 200 |
| Upsert | `Ada@Example.COM` (with surrounding spaces), `Ada Lovelace`, a valid password | Exit 0; exactly one user `ada@example.com` with that name; `Hash::check` passes; output says "Created" |
| Upsert | Run again with the same email in other casing, a new name and a new password | Exit 0; still one user, with the latest name and password; output says "Updated" |
| Upsert | Data provider of rejections: a 7-character password, empty stdin, an invalid email, a 256-character name | Exit 1; the exact validation message in the output; no user created |
| Upsert | A 7-character password for an existing user | Exit 1; name and password hash unchanged |
| Upsert | An exactly-8-character password | Accepted |
| Upsert | Stdin `pass word \r\n` (CRLF, inner and edge spaces) | Stored password is exactly `pass word ` (only the line ending is stripped) |
| Upsert | Every row above | The password is absent from the output and from every `MessageLogged` message and context. The success rows log one `info` line with the email and user id |
| Backup | No `database.backups.path` | Exit 1 with the "Set DB_BACKUP_PATH" message; `Process::assertNothingRan()` |
| Backup | Configured, on a Wednesday (`travelTo`) | Exit 0. `assertRan`: `pg_dump` with `--format=custom`, `--no-password`, the config's host, port, username and database, and `--file=…/<database>-wednesday.dump.partial`. `PGPASSWORD` is set in the process env, and the password is not in the command. The directory now exists with mode 0700, the `.dump` file exists and no `.partial` remains. One `info` line |
| Backup | 8 consecutive daily runs | Exactly 7 `.dump` files, one per weekday |
| Backup | The fake returns exit 1 with stderr, and last week's `-wednesday.dump` exists | Exit 1; last week's file is untouched; no `.partial`; one `error` line with the exit code and stderr; no log contains the password |
| Schedule | The `Schedule` event for `app:backup-database` | Expression `30 4 * * *`; `filtersPass()` is false without a path and true with one |
| Template | Names | Exactly the orchestrator's `shared/.env` list plus `DB_BACKUP_PATH`. None starts with `PIXELFORGE_` |
| Template | Secrets | `APP_KEY` and `DB_PASSWORD` are empty |
| Template | Production values | `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://prompt.sergiupopa.dev`; queue, cache and session `database`; session cookie secure, http-only and `lax`; `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS=14`, `LOG_LEVEL=info`; `DB_BACKUP_PATH` under `/home/admin/web/prompt.sergiupopa.dev/private/` |
| Smoke | `request.get('/up')` | 200 |
| Smoke | `http://` + host + `/login`, with `maxRedirects: 0` | 301; `Location` is the same URL over `https://` |
| Smoke | `request.get` of a unique unknown path. It goes through the API, because a 404 document makes Chrome log a console error that would trip the fixture | 404. The body contains "Not Found" and none of `Whoops`, `Stack trace`, `vendor/`, `SQLSTATE`, `APP_KEY`, `DB_PASSWORD` |
| Smoke | `/login` | The "Sign in" heading renders. `promptedit-session` (the slug of `APP_NAME`) is `secure`, `httpOnly` and `Lax`. `XSRF-TOKEN` is `secure` and not `httpOnly`. Every request the page made is `https://` (no mixed content) |
| Smoke | Sign in with the reviewer credentials | `/dashboard` with **Sign out** visible. It doesn't assert the placeholder heading, which PE-005 replaces |
| Smoke | Sign out | `/`; `/dashboard` then goes to `/login` |

| AC | Proven by |
| --- | --- |
| 1, 9 | Smoke (`http` → `https`, `/up`) |
| 2, 3 | `deploy.php` review; the orchestrator's first deploy (the gate refuses a red or missing run) |
| 4, 6, 7 | Runbook checks in QA: `migrate:status`, `systemctl status` after a deploy, `inspire` processed, `schedule:list` |
| 5 | `ProductionEnvTemplateTest`; the existing migrations create `jobs`, `failed_jobs`, `cache`, `cache_locks` and `sessions` |
| 8 | `BackupDatabaseCommandTest`; README restore |
| 10 | `HttpsBehindHestiaProxyTest`; the smoke's https-only requests; the README client-IP check |
| 11, 12 | Smoke; `ProductionEnvTemplateTest`; `about` in QA |
| 13 | `ProductionEnvTemplateTest`; the AC16 info row; README logs |
| 14 | `BootsWithoutPixelforgeTest`; `ProductionEnvTemplateTest` |
| 15, 19 | `ProductionEnvTemplateTest`; review |
| 16 | `UpsertUserCommandTest` |
| 17 | `production.spec.ts`, run by QA after the deploy |
| 18 | README review |

There are no JS unit or integration tests, because there is no new UI.

## Size
| Task | ≈ lines |
| --- | --- |
| 1. HTTPS pin + D-21 | 100 |
| 2. Boot without Pixelforge | 70 |
| 3. Upsert command + tests + D-23 | 230 |
| 4. Backup command, config, schedule + tests + D-22 | 230 |
| 5. Production template + test | 90 |
| 6. `deploy.php` | 110 |
| 7. Smoke, config, Makefile, tsconfig, scripts | 140 |
| 8. README | 200 |
| **Total** | **≈ 1,150**: ≈ 500 tests, ≈ 450 code and config, ≈ 200 docs |

**This is about 3× the ≈ 400-line budget.** The story's ≈ 300 left out the tests, the backup command that the orchestrator added to AC8 and the runbook's length. It ships as one PR of 8 small commits, as PE-001 to PE-003 did. If a cut is wanted, cut after task 5. PR A (≈ 720 lines) holds the app code and tests and can merge without touching the server. PR B (≈ 450 lines) holds `deploy.php`, the smoke and the runbook, and is the one the first deploy needs.

## Risks
- **Server memory.** About 580 MB is free for `npm ci` and `vite build`. An OOM fails the deploy before `deploy:symlink`, so production stays on the previous release, or on nothing yet. Fallbacks, the orchestrator's call: add swap, or build locally and upload `public/build` (a later `deploy.php` change).
- **GitHub access from the server.** If admin's key on the droplet is a per-repo deploy key for yum, it can't clone this repo. The orchestrator checks with `git ls-remote` as admin before the first deploy (a README step).
- **Backup timing.** 04:30 UTC is before Hestia's 05:10 only if the droplet's clock is UTC (DigitalOcean's default). The orchestrator confirms with `timedatectl`. Otherwise the dump is still at most a day older in Hestia's archive.
- **`pg_dump` version.** It must be ≥ the server's 17. PGDG's `postgresql-client-17` provides it, and the `pg_dump` wrapper picks the cluster's version.
- **The CI gate needs a local, authenticated `gh`.** Refusing is the safe failure. `dep rollback` deliberately has no gate.
- **Unverifiable in the pipeline.** `deploy.php` and the smoke only run for real during the orchestrator's first deploy and QA. Review should read them against the recipe facts above.
- **Password rotation** leaves the reviewer's existing sessions valid until they expire. Session invalidation belongs on PE-015's hardening list.
- **Proxy-chain drift.** If Hestia ever proxied to Apache over plain HTTP, URLs would turn `http://`. The smoke's https-only request check and the console-error fixture (mixed content) catch that. Never fix it with `trustProxies(at: '*')` (D-21).
- **Env leakage between tests.** `BootsWithoutPixelforgeTest` must restore what it strips, or later suites lose `PIXELFORGE_*` values that `phpunit.xml` may set from PE-009 on.
- **`artisan:migrate` uses `skipIfNoEnv`.** An empty `shared/.env` would skip migrations silently. The orchestrator pre-creates the file, and `migrate:status` in QA catches a skip.
