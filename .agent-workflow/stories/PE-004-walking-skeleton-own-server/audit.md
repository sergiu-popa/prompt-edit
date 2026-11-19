# Audit — PE-004-walking-skeleton-own-server

**Verdict: PASS**

Run on branch `pe-004-walking-skeleton-own-server` from `6a6f3ad` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (APPROVE, round 2) and `qa.md` (QA PASSED) present.

## Scope

I checked the whole `[PE-004]` diff (`git diff main...HEAD`) against `story.md` AC1–19, `plan.md`, both review rounds, the QA notes and `.agent-workflow/CLAUDE.md`. Where the auditor checklist mentions Livewire, the project's Inertia/Vue rules apply instead. The diff covers:
- `deploy.php` and `.env.production.example`;
- `UpsertUserCommand`, `BackupDatabaseCommand`, `config/database.php` and `routes/console.php`;
- the Makefile target, `playwright.production.config.ts` and `tests/smoke/production.spec.ts`;
- the README runbook;
- every PHPUnit file the story adds.

Each claim about a dependency was checked against its installed source:
- Deployer 8.0.5;
- Laravel 13's `TrustProxies`, `TrustHosts`, scheduler and `Process`;
- Playwright 1.65.1's failure artifacts.

I made no SSH connection, ran no deploy and wrote nothing to production. Production was probed only with read-only `curl` GETs (2026-11-19, about 14:25–14:31 UTC).

## What was verified

- **No secrets in the repo, the README or test output.**
  - `git grep` finds no `APP_KEY`, `DB_PASSWORD` or `PIXELFORGE_*` value apart from CI's throwaway `secret`. `.env` is not tracked.
  - Every secret in the README is generated into a shell variable or read from `shared/.env`. It reaches a process through stdin (`printf` builtin) or the environment (`perl`, `PGPASSWORD`), never through argv.
  - The smoke's failure output did hold the reviewer password (#2, fixed).
- **The account command and the backup command never log or print passwords.**
  - `UpsertUserCommand` reads stdin, refuses a TTY, and prints only Laravel's validation messages, which never echo values. Its log line holds only the email and the user id.
  - A failing `updateOrCreate` reports the bcrypt hash in the `QueryException` bindings, never the password. Bcrypt on PHP 8.4 doesn't throw on passwords over 72 bytes (checked in the container).
  - `BackupDatabaseCommand` passes the password only in `PGPASSWORD`. Its failure log holds the exit code and pg_dump's stderr. A timeout message (Symfony's) holds the command line, which has no password.
- **No `trustProxies`, and spoofed `X-Forwarded-*` headers are ignored.**
  - Laravel has no `trustProxies` call and no `trustedproxy` config, and `laravel_cloud()` is false on the droplet.
  - On production, `X-Forwarded-Proto`, `-Port`, `-Prefix`, `Forwarded`, `X-Original-URL` and `X-Rewrite-URL` change nothing.
  - **`X-Forwarded-Host` did change the host (#1).** The server chain hands it to PHP as the Host. That is now refused in the app.
- **Cookie flags.**
  - `promptedit-session` is `secure; httponly; samesite=lax`.
  - `XSRF-TOKEN` is `secure; samesite=lax`, without `HttpOnly`.
  - Responses are `cache-control: private` and carry HSTS.
- **Debug is off.**
  - An unknown URL and `GET /logout` return plain pages, with none of `Whoops`, `Stack trace`, `vendor/`, `Exception` or `Illuminate`.
  - `/.env`, `/deploy.php`, `/composer.json` and `/.git/config` answer 404, and `/storage/logs/laravel.log` answers 403.
  - The template pins `APP_DEBUG=false`, and QA recorded `about` showing debug off.
- **The `deploy.php` CI gate can't be bypassed silently.**
  - The gate refuses each of these with a clear message: `--tag`, `--revision`, `-o target=<sha>`, an unpushed branch, a missing `gh`, no CI run, a run still in progress and a failed run.
  - `dep rollback` is deliberately ungated.
  - The gate trusted `git ls-remote`'s first matching line, which could be another branch (#3, fixed).
  - The fixed gate was run locally against a copy of `deploy.php` with the host swapped for `localhost()`, so nothing touched the server:
    - with no flags, it passed on `main` at `5455098` (run 44531521905);
    - against a scratch repo with branches `main` and `a/refs/heads/main`, it picked `main`'s sha;
    - with `--branch=nope`, it refused.
  - `dep tree deploy` is unchanged.
- **The runbook's sudoers lines are scoped to exact commands.**
  - The new file grants only `systemctl start|stop|restart prompt-edit-worker`. The unit runs as `admin`, so the grant gives `admin` no new privilege.
  - The FPM reload line is yum's existing rule. It is not duplicated.
  - Every `sudo` in the README and in `deploy.php` matches one of those lines.
- **The runbook keeps secrets off command lines and leaves the other sites alone.**
  - No secret is on argv: step 3 pipes the SQL into `psql`, step 4 uses the environment, and the restore uses `PGPASSWORD`.
  - Yum and azsemaus are untouched:
    - the Hestia commands name only `prompt.sergiupopa.dev`;
    - Postgres gets its own `conf.d` drop-in and listens on localhost;
    - the sudoers rule is a new file;
    - nothing touches MariaDB, Redis or another domain's pool.
  - The shared FPM reload is the one yum already does.
- **`make e2e` and CI never pick up the production smoke.**
  - The default config's `testDir` is `tests/e2e`.
  - Vitest includes only `tests/js/**`.
  - CI runs `npx playwright test` with the default config.
  - `--list` with the default config shows no smoke test. The production config lists the 6 smoke tests.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | **Production honours a client's `X-Forwarded-Host`.** `curl -H 'X-Forwarded-Host: attacker.example' https://prompt.sergiupopa.dev/dashboard` answered `302 Location: https://attacker.example/login`, and `/login` loaded its CSS and JS from `https://attacker.example/build/…`. Laravel trusts no proxy (`X-Forwarded-Proto: http` changes nothing), so the server chain itself hands the header to PHP as the Host. Responses are `private`, and a clean request afterwards shows no trace of the spoofed host, so nothing is cache-poisoned. The damage is limited to the requester's own response. But it breaks D-21's claim that a client can't spoof the host. It also breaks this audit's rule that spoofed `X-Forwarded-*` headers are ignored. | `bootstrap/app.php:18` | Warning | Fixed | **`4b24afa`.** `$middleware->trustHosts()` limits the app to APP_URL's host and its subdomains, which include Hestia's `www.` alias. Any other host gets a 400. The middleware compares the same `getHost()` value that produced today's correct `https://prompt.sergiupopa.dev/…` URLs, so legitimate requests pass. It is skipped in `local` and `testing`, so dev, CI E2E (APP_ENV `local` from `.env.example`) and PHPUnit are unaffected. Tests and docs: <ul><li>three `HttpsBehindHestiaProxyTest` rows run the request as production: the spoofed host gets 400, and the own host and `www.` still redirect; the spoof row redirected to `attacker.example` before the fix;</li><li>a new smoke test asserts that `attacker.example` never appears in `/login`'s or `/dashboard`'s `Location` header or body;</li><li>the README and D-21 now say so.</li></ul> **Until this is deployed, that smoke test fails on production, as it should.** |
| 2 | **The smoke's failure output wrote the reviewer password in plaintext.** On a failed test, Playwright writes `test-results/…/error-context.md` with an ARIA snapshot of the page, and the snapshot lists each input's `value`. A password input counts as a `textbox`, so its value is included. A probe (a filled password field and a failing `expect`) produced `- textbox "Password": <value>`. Turning traces off didn't cover this. | `playwright.production.config.ts:12-18`, `tests/smoke/production.spec.ts:83-87` | Warning | Fixed | **`2ccfc9b`.** The config sets `PLAYWRIGHT_NO_COPY_PROMPT`, which Playwright 1.65 checks before taking the page snapshot. A failing locator or page matcher still attaches its own snapshot, so the reviewer test now waits with `page.waitForURL('/dashboard')` while the password is in the form. A comment explains why. Re-probed after the fix: a failing `click()` and a failing `waitForURL` leave no value on disk. A scratch E2E run of the same flow against the local app passed in both projects. The probe files and `test-results/` were deleted. |
| 3 | **The CI gate could pin the wrong commit.** `git ls-remote <repo> refs/heads/main` matches the tail of every ref, so it also lists `refs/heads/x/refs/heads/main`. When that branch sorted first, the gate took its sha and reported "CI passed on main at <sha>". It then deployed that branch's head as `main`. Reproduced with a scratch repo. It needs push access to create such a branch, but the misdeploy would be silent. | `deploy.php:48-64` | Warning | Fixed | **`adf3247`.** The gate keeps only the line whose ref equals `refs/heads/<target>` exactly, and refuses otherwise. Verified locally as described above. |
| 4 | **A timed-out `pg_dump` threw out of the backup command.** A dump past the 300 s timeout threw `ProcessTimedOutException`, which left the `.partial` file behind and wrote no error line of the command's own. The case is real: a dump that waits on a lock held by a migration would time out. | `app/Console/Commands/BackupDatabaseCommand.php:45-65` | Suggestion | Fixed | **`00b9798`.** A timeout now deletes the partial, logs one `error` line with `timeout_seconds` and returns 1, like a failed dump. Last week's file is kept. A new test row fakes the timeout. It fails without the fix. |
| 5 | **The backup's overlap lock lasted 24 hours.** `withoutOverlapping()` defaults to a 1,440-minute lock. A run killed hard (a reboot or the OOM killer; SIGTERM already releases the lock) leaves the lock behind, and it expires within a second of the next 04:30 run, which can then be skipped. PE-014's every-minute reconciler is meant to copy this entry, and with the default a single crash would stop it for a day. | `routes/console.php:21` | Suggestion | Fixed | **`b3a2174`.** `withoutOverlapping(expiresAt: 10)`, just past the 300 s timeout. The comment explains the choice, and the schedule test asserts `expiresAt` = 10. |
| 6 | **The server-side cause of #1 is unknown.** It is likely mod_rpaf's `RPAF_SetHostName` or Hestia's nginx proxy settings. The same layer may also act on a client's `X-Forwarded-For` or `X-Real-IP`. Yum, on the same droplet, is probably exposed the same way. | Hestia nginx/Apache config (droplet) | Suggestion | Fixed on the server (orchestrator) | **Resolved 2026-11-19 with the user's approval.** The cause is Hestia's `mod_rpaf` (`RPAFsethostname On`) combined with nginx passing a client's own `X-Forwarded-Host` through. It affected every site on the droplet, yum included. nginx now sets `X-Forwarded-Host $host` and `X-Forwarded-Proto $scheme`; afterwards the spoofed probe redirects to each site's own host. The step is in the README runbook (step 8). Original note: **Orchestrator / user.** The app-level fix (#1) removes the app's exposure. Fixing the server touches the shared droplet, which this audit may not do. Read-only checks as `admin`: <ul><li>`apache2ctl -M \| grep -Ei 'rpaf\|remoteip'`;</li><li>`grep -rniE 'sethostname\|x-forwarded-host' /etc/apache2 /etc/nginx`.</li></ul> Fold the result into QA open item 2, see #8. |
| 7 | **The PR is far over budget.** It has about 2,110 changed lines against the ≈400-line budget (carried from review rounds 1 and 2). | `.agent-workflow/CLAUDE.md` § Git hygiene | Warning | Deferred | This is a process decision, not a code defect. Splitting now would rewrite history, which the pipeline forbids. **The user decides before opening the PR:** one PR, or sequential PRs split at `28d2668`, as the plan proposed. This audit adds about 130 lines. |
| 8 | **QA left five production runtime checks open.** <ol><li>AC6: an `inspire` job is processed.</li><li>AC10: the client IP in the throttle key.</li><li>AC8: the first real dump on 2026-11-20 at 04:30 UTC.</li><li>AC4: Postgres listens on 127.0.0.1 only.</li><li>AC7: the cron entry exists.</li></ol> | `qa.md` § Open items | Warning | Fixed except AC8 (orchestrator) | **Confirmed 2026-11-19 (see `qa.md` § Orchestrator follow-up): AC6, AC10 (including the spoofed `X-Forwarded-For` and `X-Real-IP` variants), AC4 and AC7. AC8 waits for the first 04:30 UTC dump.** Original note: **The orchestrator must confirm these before the story is closed.** They need SSH, which QA and this audit may not use. Because of #1, run item 2 twice more, once with a spoofed `X-Forwarded-For: 203.0.113.9` and once with a spoofed `X-Real-IP: 203.0.113.9`. The throttle key must still end in the operator's real IP. |
| 9 | **A failed `CREATE ROLE` can log the database password.** If step 3 of the runbook fails, for example when it is re-run after the role exists, Postgres logs the failing statement, password included (`log_min_error_statement = error`). The log is `/var/log/postgresql/postgresql-17-main.log`. | `README.md:139-141` | Suggestion | Skipped | The step already ran once and succeeded. That log is readable only by `postgres` and group `adm`. A re-run fails before it changes anything, and the remedy (rotate the password) is the normal one. Adding branches to a one-time step would make it harder to paste. |
| 10 | **A user prompt was missing from the log (review round 2 suggestion).** The user's provisioning-process prompt wasn't in `.agent-workflow/prompts.md`. | `.agent-workflow/prompts.md` | Suggestion | Fixed (orchestrator) | Added in `54185f0` (prompts 8 and 9). |

No Critical issues were found.

## Deploying these fixes

All of them ship with the next normal `dep deploy` from `main` after merge. None needs a migration, a `shared/.env` change or any server change.
- `deploy.php` (#3) and the smoke (#2, #1's check) run on the operator's machine. They take effect from a checkout of the merged `main`.
- `trustHosts()` (#1) takes effect when the deploy reloads PHP-FPM.
- The new lock expiry (#5) takes effect at the next cron `schedule:run` from the new `current`.

After the deploy, `make smoke-production` must pass all 6 tests. The new host test is the production check for #1. If it unexpectedly answers 400 for legitimate requests, `dep rollback` restores the previous release.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `make check` | Exit 0: <ul><li>Pint passes on 84 files, and Larastan reports no errors.</li><li>PHPUnit: 410 passed (1,561 assertions). That is 406 before the audit, plus 1 timeout row and 3 trusted-host rows.</li><li>Vitest: 107 passed in 9 files.</li></ul> |
| Playwright, desktop and mobile | `make e2e` | 12 passed |
| Production smoke, listing only | `playwright.production.config.ts --list` with dummy `https://` values | 6 tests listed; the default config lists none |
| Failure-artifact probe | Scratch `tests/smoke/zz-audit-probe.spec.ts`, deleted | Before #2: the password value was in `error-context.md`. After: no value from a failing `click()` or `waitForURL`, while a failing matcher still writes it (hence `waitForURL`) |
| Sign-in flow with `waitForURL` | Scratch `tests/e2e/zz-audit-probe.spec.ts` against the local app, deleted | 2 passed |
| CI gate | `dep deploy:ci-gate` on a `localhost()` copy of `deploy.php` in the scratchpad | Passed on `main` (`5455098`). Picked the exact `main` ref when a decoy branch was present. Refused an unknown branch |
| Production probes | Read-only `curl` | Results as listed under *What was verified* and in #1 |

Neither probe file is in the repository. Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-004/audit-make-check.log` and `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-004/audit-e2e.log`.
