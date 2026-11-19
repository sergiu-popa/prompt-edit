# QA — PE-004-walking-skeleton-own-server

**Verdict: QA PASSED**, with five production runtime checks that only the orchestrator can run (see *Open items*).

Run on branch `pe-004-walking-skeleton-own-server` at `54185f0`. Started 2026-11-19 14:14 UTC. Prerequisites checked: story status `qa-ready`, review verdict `APPROVE` (round 2).

## Tests

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, JS lint/format/types, Vitest | `make check` | Exit 0. Pint clean on 84 files, Larastan no errors, PHPUnit 406 passed (1,544 assertions), Vitest 107 passed in 9 files. |
| JS lint/format/types | `make js-check` | Included in `make check`: ESLint, Prettier (now including `tests/smoke`) and vue-tsc clean. |
| Vitest unit and integration | `make js-test` | Included in `make check`: 107 passed. |
| Existing E2E (desktop + mobile) | `make e2e` | 12 passed (6 per project), after `npm run build`. |
| Production smoke, listing only | `playwright.production.config.ts --list` with dummy `https://` values | 5 tests listed. The default config lists 0 `tests/smoke` tests. |
| Production smoke, live run | `make smoke-production` (orchestrator) | 5 passed. QA did not run it (reviewer password is kept out of agent sessions). |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-004/make-check.log`, `.../PE-004/e2e.log`.

## Acceptance criteria

Evidence comes from a passing test, from QA's own read-only production probes (2026-11-19, about 14:15 UTC), or from the orchestrator's production checks (2026-11-19, about 14:13 UTC). The orchestrator's checks are marked *(orchestrator)*.

| AC | Covered by | Result |
| --- | --- | --- |
| 1 HTTPS and cert, `APP_URL`, http→https | curl: `https://prompt.sergiupopa.dev/login` verifies (Let's Encrypt YE1, notAfter 2027-02-17). `http://…/login` → 301 to `https://…/login`. `APP_URL` shown by `about` *(orchestrator)*. Smoke `plain http redirects to https` *(orchestrator, 5 passed)*. | Pass |
| 2 `deploy.php` shape | Code review round 2 (`dep tree` order, `recipe/laravel.php`, `artisan:reload`). Orchestrator deploy run: release 1, `current` → `releases/1`, FPM reloaded *(orchestrator)*. | Pass |
| 3 CI gate | Gate passed on `54185f0` (run 44540531755) *(orchestrator)*. The refusal branches were reviewed in code, not executed: QA may not run `dep`. | Pass (refusal branches reviewed only) |
| 4 Postgres 17, `prompt_edit` role | `about`: Database pgsql. `migrate:status`: 3 Ran *(orchestrator)*. Localhost-only listen not re-checked (see Open items). | Pass (partly evidenced) |
| 5 Database stores | `ProductionEnvTemplateTest` (`QUEUE`/`CACHE`/`SESSION` = `database`). `about`: Cache, Queue and Session database *(orchestrator)*. Tables come from the 3 ran migrations. | Pass |
| 6 Worker unit | `systemctl start prompt-edit-worker`: active, started 14:13:03 UTC on `releases/1` *(orchestrator)*. Processing a dispatched job not evidenced (see Open items). | Partly evidenced |
| 7 Scheduler | `schedule:list` runs and shows `30 4 * * * app:backup-database` *(orchestrator)*. The every-minute cron entry is in the runbook but not confirmed on the server. | Pass (partly evidenced) |
| 8 Nightly `pg_dump` | `BackupDatabaseCommandTest` (no path, dump into the weekday file, 8 runs keep 7 files, failed dump keeps last week's copy, schedule `30 4 * * *`, gated on the path). README has the restore steps. First real dump on the server runs at 04:30 UTC 2026-11-20 (see Open items). | Pass (first real run pending) |
| 9 `GET /up` 200 | curl: `/up` 200 *(and orchestrator)*. | Pass |
| 10 HTTPS URLs, client IP, no `trustProxies('*')` | `HttpsBehindHestiaProxyTest`: guest https redirect, http control, https sign-in, forwarded headers ignored from `127.0.0.1` and the server IP, throttle keyed on `REMOTE_ADDR`. Production: the login page's assets and forms are all `https://`, with no `http://` references (curl). The public client IP as Laravel sees it on the server is not evidenced (see Open items). | Pass (feature tests and HTML); client IP on production open |
| 11 Cookie flags | curl on production `/login`: `promptedit-session` has `secure; httponly; samesite=lax`. `XSRF-TOKEN` has `secure; samesite=lax`, with no HttpOnly. Smoke asserts the same *(orchestrator, 5 passed)*. | Pass |
| 12 Errors: debug off, plain 404 | curl `/does-not-exist-pe004` → 404, body contains "Not Found" and none of `Whoops`, `Stack trace`, `vendor/`, `SQLSTATE`, `APP_KEY`, `DB_PASSWORD`, `PIXELFORGE`, `/home/admin`, `Exception`, `Illuminate`. `about`: debug OFF *(orchestrator)*. | Pass |
| 13 Logs: daily channel, 14 days, info | `ProductionEnvTemplateTest` (`LOG_CHANNEL=daily`, `LOG_DAILY_DAYS=14`, `LOG_LEVEL=info`). The `User created` info line reached production's log *(orchestrator)*. README has the log paths. | Pass |
| 14 No Pixelforge config, pages work without it | `BootsWithoutPixelforgeTest` (precondition, guest pages, signed-in home). `ProductionEnvTemplateTest` (no `PIXELFORGE_*` names). Production: `/up`, `/` and `/login` return 200 (curl). | Pass |
| 15 `.env.production.example` | Committed. `ProductionEnvTemplateTest`: names exact, secrets blank (`APP_KEY=`, `DB_PASSWORD=` in the file). | Pass |
| 16 Reviewer account command | `UpsertUserCommandTest`: create, idempotent update, lowercasing, 8-character rule, CRLF stripping, password absent from output and logs, TTY refusal. Production: "Created user", the log line has email and user id, and a grep of the log for the password found 0 hits *(orchestrator)*. | Pass |
| 17 Opt-in Playwright smoke | Listing: 5 tests in `tests/smoke`, 0 in the default config. Live: 5 passed *(orchestrator)*. | Pass |
| 18 README runbook | README `## Production` has the live URL, the provisioning runbook (Hestia, Postgres, systemd, sudoers, scheduler), the reviewer account, the first deploy, the deploy and rollback runbook, the redeploy rule, the Pixelforge note and warning, logs, and backups with restore. | Pass |
| 19 No secrets committed | `git ls-files` has no `.env`. `.env.production.example` has blank `APP_KEY` and `DB_PASSWORD`. The working tree is clean. | Pass |

## Edge cases probed

- Unknown URL on production: plain 404, no internals in the body (curl).
- Plain http on production: 301 to the same path over https (curl).
- Certificate verification: `curl` without `-k` verifies the chain; the subject matches the host.
- Smoke isolation: the default Playwright config lists no `tests/smoke` test, so `make e2e` and CI cannot pick it up.
- Password boundaries and idempotence in the account command: covered by `UpsertUserCommandTest` (7 characters rejected with no change; 8 accepted; CRLF stripped).
- Backup failure: `BackupDatabaseCommandTest` keeps last week's file and logs no password.
- Forwarded-header spoofing: `HttpsBehindHestiaProxyTest` ignores every forwarded header from every address.

## Regressions

None. `make check` and `make e2e` pass unchanged. The welcome and auth E2E specs pass in both projects.

## Visual verification

No new UI in this story. The screens it touches are the PE-003 pages (`/login`, `/`, `/dashboard`), so the visual pass covered the two guest pages locally. The signed-in home is covered by the existing E2E no-scroll test and by the production smoke's sign-in.

Driven with a scratch Playwright script at `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-004/visual.cjs`, run in the Playwright container against the local app. The browser was not pointed at production (only read-only curl was allowed there).

| Width | Screens | scroll/client | Console or page errors |
| --- | --- | --- | --- |
| 375 | `/login`, `/` | 375/375 on both | none |
| 768 | `/login`, `/` | 768/768 on both | none |
| 1440 | `/login`, `/` | 1440/1440 on both | none |

Both pages returned 200 with a stylesheet loaded. Headings: "Sign in" and "Generate images with Pixelforge".

Screenshots (full page), not committed:
- `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-004/login-375.png`
- `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-004/login-768.png`
- `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-004/login-1440.png`
- `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-004/welcome-375.png`, `welcome-768.png`, `welcome-1440.png` (same folder)

Looked at `login-375.png`: centred card, "Sign in" heading, labels above the inputs, full-width Sign in button and the "Create one" link. It matches the PE-003 layout.

Persistence and fixtures: the visual pass only issued GETs. It created no users and no rows. The production reviewer account was created by the orchestrator, and its log line is recorded above.

## Open items

These are production runtime checks QA cannot run. QA may not SSH, run `dep`, or write to production. The orchestrator should confirm them before the story is closed. Each is a first-deploy check from the README's runbook.

1. **AC6, job processed.** Run `Artisan::queue('inspire')` via `php8.4 artisan tinker --execute` in `current/`. Then `journalctl -u prompt-edit-worker` should show it processed, and the `jobs` table should be empty.
2. **AC10, client IP.** Make one failed sign-in with a throwaway email from the operator's machine. The `cache` table key for `…<email>|<ip>` should hold the operator's public IP, not `127.0.0.1` or `164.90.236.77`.
3. **AC8, first real dump.** The scheduler runs at 04:30 UTC on 2026-11-20. Confirm `private/backups/<db>-friday.dump` exists and that `pg_restore --list` reads it.
4. **AC4, listen address.** `ss -ltn` should show 5432 on 127.0.0.1 only.
5. **AC7, scheduler cron.** `v-list-cron-jobs admin` should show the every-minute `schedule:run` entry.

## Notes (non-blocking)

- The app container has no `pg_dump`, so a local manual `app:backup-database` run fails with exit 127 (seen in `storage/logs/laravel.log` at 13:43 UTC). Production installs the PGDG client with `postgresql-17`, so this is a dev-only gap. Dev never sets `DB_BACKUP_PATH`.
- Older `local.ERROR` lines from 12:51 UTC (Redis `getaddrinfo` failures) predate the bootstrap that removed Redis. The current code does not reach Redis.
- The `testing.ERROR: Database backup failed` lines are the intentional failure-path test (`BackupDatabaseCommandTest`, fake `connection to server failed`). They are not real failures.
- The smoke's reviewer sign-in is not exercised by QA, so `make smoke-production` is evidence from the orchestrator only.

## Verdict

**QA PASSED.** Every AC maps to a passing test or to production evidence, the local suites are green, the production probes match the story, and review round 2 has no Critical items open. The five open items above are production checks that are not yet evidenced, so the orchestrator should confirm them before closing the story.
