# Review — PE-004-walking-skeleton-own-server

## Round 1

**Verdict: REQUEST CHANGES**

Scope reviewed: the `[PE-004]` commits `7cea8a1..b1acb59` on `pe-004-walking-skeleton-own-server` (`git diff origin/main...HEAD`), checked against `story.md` and `plan.md`. Nothing was run against the droplet. This review is code-only. Provisioning and the first deploy come after approval, as the story says.

Checks run on HEAD:
- `make check`:
  - Pint passes on 84 files and Larastan reports no errors.
  - PHPUnit runs 405 tests with 1,537 assertions, all passing.
  - ESLint, Prettier (now including `tests/smoke`) and vue-tsc are clean.
  - Vitest runs 107 tests, all passing.
- Playwright `--list`:
  - With dummy `https://` values, `playwright.production.config.ts` lists the 5 smoke tests in `tests/smoke/production.spec.ts`.
  - Without those values it refuses to load ("Set E2E_BASE_URL to the https:// production URL…").
  - The default config lists no smoke test, so `make e2e` and CI can't pick the smoke up.
- `dep tree deploy` and `dep tree rollback` (local, Deployer 8.0.5, no server contact) give this order:
  - `deploy:ci-gate` runs before `deploy`;
  - npm install and build run after `deploy:vendors`;
  - `deploy:php-fpm:reload` runs after `deploy:symlink`, inside `deploy:publish`;
  - `artisan:reload` runs last;
  - `rollback` is followed by the FPM reload and `artisan:queue:restart`.
- I checked the plan's claims against the 8.0.5 phar source and `vendor/`, and each one holds:
  - `recipe/laravel.php` ends `deploy` with `artisan:reload` (min Laravel 12.41), and Laravel 13's `ReloadCommand` runs `queue:restart` and `schedule:interrupt`.
  - `target` honours `--branch`.
  - `Master` persists host config that a task `set()`s, so pinning `target` to the CI-approved sha reaches `deploy:update_code`.
  - `artisan:migrate` is `skipIfNoEnv`.
  - `deploy:env` copies `.env.example` only when no `.env` exists, and `deploy:shared` then links `shared/.env`.
- `.github/workflows/ci.yml` runs `on: push` only. A run found by `gh run list --commit <sha>` therefore tested that exact commit and not a PR merge ref, so the gate checks the right thing.

### Summary

The implementation matches the plan file for file. The deviations from the story are the ones the plan argued for, and I accept each of them:
- **AC10, no trusted proxies (D-21).** The story's "trusted proxy + `X-Forwarded-Proto`" row became two rows:
  - `HTTPS=on` gives `https://` URLs;
  - every forwarded header is ignored from loopback and from the server IP.

  This is narrower than trusting loopback, and it matches how Hestia's nginx → Apache (mod_remoteip) chain hands PHP the scheme and the IP.
- **AC8.** The nightly dump is a scheduled command, not a second cron (D-22).
- **AC2.** The worker restart comes from the recipe's `artisan:reload`, and a comment in `deploy.php:115-118` says why.
- **AC12.** The proof that debug is off is `artisan about` in QA plus the template test, because a 404 renders the plain view even with debug on.

What I checked in each part:
- **`deploy.php`:**
  - It follows `../yum/deploy.php` line for line where that applies.
  - The CI gate runs only `runLocally`/`testLocally`, and it runs before anything connects to the server.
  - It refuses with a clear message in each case: `gh` missing, branch not on origin, no run, run still in progress, or a run that didn't succeed. It then pins `target` to the sha that CI approved.
  - Rollback also reloads FPM and restarts the worker.
  - `deploy:failed` unlocks.
- **`UpsertUserCommand` (AC16):**
  - The password comes from stdin, and only one trailing `\r?\n` is stripped.
  - The validation rules are registration's, minus `confirmed` and `unique`.
  - The write is `updateOrCreate` keyed by the lowercased email, with no factory or Faker.
  - The command logs one `info` line with only the email and the user id, and the password parameter is `#[SensitiveParameter]`.
  - `UpsertUserCommandTest` covers every plan row. It drives `CommandTester` with real stdin, and it scans the output plus every `MessageLogged` message and context for the password.
- **`BackupDatabaseCommand` (AC8):**
  - It builds the command as an array, and the password reaches `pg_dump` only through `PGPASSWORD`.
  - It writes a `.partial` file first and renames it on success. A failed run deletes the partial and leaves last week's file untouched.
  - The schedule entry is gated on `database.backups.path`. The tests pin the exact argv, the env, the timeout, the 0700 directory, the 7-file rotation, the failure path and the schedule filter.
- **`BootsWithoutPixelforgeTest` (AC14):**
  - It strips `PIXELFORGE_*` after `LoadEnvironmentVariables` and puts the values back in `tearDown`.
  - Its precondition row makes the test fail on a dev `.env` that holds the key, so it can't pass by accident.
- **`.env.production.example` (AC5, 11–15, 19):**
  - It holds every name and has blank secrets.
  - It has no `PIXELFORGE_*` names.
  - `ProductionEnvTemplateTest` pins it with `Dotenv::parse`.
- **Smoke (AC17):**
  - It imports the console-error fixture.
  - Traces and videos are off, so a trace can't record the password.
  - It checks the cookie flags against the real cookie name (`promptedit-session`, the slug of `APP_NAME`), and it checks that no request is plain http.
  - The 404 check goes through the API, so a 404 document can't trip the console fixture.
  - The `make smoke-production` target passes the variables with bare `-e NAME`, so their values never appear on a command line.
- **README (AC18):** it has every part the story lists: the live URL, the provisioning runbook, the reviewer account, the first deploy and its checks, the deploy and rollback runbook, forward-only migrations, the redeploy-and-smoke rule, the per-key Pixelforge note and warning, logs, and backups with a restore procedure.
- **Security:**
  - No secret is committed.
  - The reviewer password travels by a `printf` builtin into ssh's stdin, and `shared/.env` secrets are written through `perl`'s environment, so neither is on argv.
  - The exception is the database role step (Warning 1).

### Commit hygiene

The history reads cleanly:
- one bookkeeping commit for the plan (`7cea8a1`) and one for the checkboxes and status (`b1acb59`);
- one commit per plan task, each holding its own tests;
- subjects in `[PE-004] Imperative …` form, all under 72 characters, each with the trailer.

The D-21, D-22 and D-23 rows in `requirements.md` ride with the code commits that introduce them (`cce12b2`, `88b3c63`, `5a1abf8`). The plan specified this and PE-003 did the same, so it is fine. `e1572f2` (`deploy.php`) has no automated test, which the plan accepts: it can only be exercised by the real deploy.

### Warnings

- [x] **`README.md:137-141`: the runbook puts the Postgres password on a command line, which breaks AC19.**
  - Step 3 runs `sudo -u postgres psql … -v pw="$PE_DB_PASSWORD"`, so the generated password becomes an argument of both `sudo` and `psql`.
  - `sudo` logs the full command line to syslog by default (`/var/log/auth.log`: `COMMAND=/usr/bin/psql -q -v ON_ERROR_STOP=1 -v pw=<password>`). The password would then persist on disk outside `shared/.env`, contrary to AC19: "the Postgres password live[s] only in `shared/.env` on the server or in the operator's shell".
  - While `psql` runs, the password is also visible to every local user through `ps` and `/proc/<pid>/cmdline`. That is the exposure D-23 cites for the account command on this shared droplet.
  - Fix: build the SQL with the shell's `printf` builtin, which puts nothing on argv, and pipe it to psql's stdin. The password is hex, so it needs no quoting:
    ```bash
    printf "CREATE ROLE prompt_edit LOGIN PASSWORD '%s';\nCREATE DATABASE prompt_edit OWNER prompt_edit ENCODING 'UTF8' TEMPLATE template0;\n" "$PE_DB_PASSWORD" \
      | sudo -u postgres psql -q -v ON_ERROR_STOP=1
    ```
    Then update the comment above it. The current one says psql `-c` can't interpolate `:'pw'`, which no longer applies. Also add one line to the plan's "Reviewer account" / D-23 reasoning, or to the README, saying that secrets never go on a `sudo` command line because sudo logs it.

  — fixed in `4d7f760`: step 3 builds the SQL with the `printf` builtin and pipes it to `sudo -u postgres psql`. The comment above it now says no secret goes on a command line, because sudo logs argv to `auth.log` and `ps` shows it to every local user.
- [x] **The PR is far over the size rule.** `.agent-workflow/CLAUDE.md` sets about 400 changed lines per PR. This branch has 1,630 insertions:
  - code and config (`app/`, `config/`, `routes/`, `deploy.php`, `.env.production.example`, the Makefile, Playwright config, `package.json`, `tsconfig.json`): about 417;
  - tests: about 833;
  - README: about 186;
  - `.agent-workflow`: about 194.

  The plan foresaw this (§ Size) and named a cut after task 5 (`28d2668`). PR A, the app code and tests (about 720 lines), can merge without touching the server. PR B, `deploy.php`, the smoke and the runbook (about 450 lines), is the one the first deploy needs. Each commit is reviewable on its own: the largest, `5a1abf8`, is 320 lines, and 218 of them are its test.

  — acknowledged: splitting now means rewriting history, which no engineering action in this pipeline may do. **The user decides before the PR is opened:** one PR of 10 commits, or sequential PRs split at `28d2668`.

### Suggestions

These are optional and don't block the verdict.

- [x] `app/Console/Commands/UpsertUserCommand.php:79`: run interactively, without a pipe, the command waits on `fgets(STDIN)` with no prompt and echoes the password as it is typed. Fix: when `stream_isatty(STDIN)` and no test stream is set, either read the password with `$this->secret('Password')` or fail with "Pipe the password on stdin (see README)". Add one test row for whichever is chosen.

  — fixed in `e878988`: the command now refuses when its password stream is a TTY, with "Pipe the password on stdin instead of typing it (README, "Reviewer account")", before it reads anything or writes a log line. `test_it_refuses_to_read_the_password_from_a_terminal` opens `/dev/ptmx` as a stand-in terminal. The README notes the refusal.
- [x] `routes/console.php:16-18`: the story's Technical Notes ask scheduled tasks to use `withoutOverlapping()`, which uses the database cache lock. The backup can't overlap itself today, since it is due once a day and times out after 300 s. Adding `->withoutOverlapping()` now sets the pattern that PE-014's every-minute reconciler will copy, and it costs one line plus a `withoutOverlapping` assertion in the schedule test.

  — fixed in `b011411`: the backup entry is `->withoutOverlapping()`, and the schedule test asserts it.
- [x] `deploy.php:55`: the gate hard-codes `--repo sergiu-popa/prompt-edit`, while the `repository` setting already holds the same slug. Fix: derive it once, for example with `preg_replace('~^git@github\.com:|\.git$~', '', $repository)`, or set it as a `github_repo` setting, so the two can't drift apart.

  — fixed in `15800be`: the gate derives `owner/name` from `repository` with that `preg_replace`. The resulting `gh run list --repo sergiu-popa/prompt-edit …` call was checked locally against `main`'s head.
