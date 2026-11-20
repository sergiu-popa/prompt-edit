# Review — PE-015-production-go-live

## Round 1

**Verdict: REQUEST CHANGES**

Scope reviewed: the 25 `[PE-015]` commits `58c95a9..bcc6d81` on `pe-015-production-go-live` (`origin/main..HEAD`), against `story.md` and `plan.md`. They are the prompt log, the plan, 22 task commits (task 6 is split over two), the decisions and the bookkeeping. All 21 plan tasks are checked.

Checks run on HEAD (`bcc6d81`):
- `make check` exits 0. Pint (292 files) and Larastan are clean. PHPUnit runs 1,728 tests (37,027 assertions), all passing. ESLint, Prettier and vue-tsc are clean. Vitest runs 503 tests in 33 files, all passing.
- `make e2e` (after `make restart-worker`) exits 0, with 44 passed and 2 skipped. The skips are the existing touch-only and mouse-only rows of `generate.spec.ts`. The new expired-session row passes on desktop (4.1 s) and mobile (1.2 s).
- `make live-smoke` was run once against the real API with the dev key. All 6 tests passed (58 assertions) in 12.9 s. The printed line shows input text 12 tokens (inside 11–15, so code points are counted), image 0, output 280 and reasoning 282, for a provider cost of 14,785,000 n$ against the 17,525,000 n$ max. A search of the output for `Bearer`, `pf_` and `whsec_` finds nothing.
- `phpunit --list-tests` lists no `LivePixelforgeSmokeTest` test by default. With `--group=live` it lists exactly the 6 live tests, so the CLI group overrides the `phpunit.xml` exclude as the Makefile target expects.

There is no Critical finding. Three Warnings must change. W3 needs the user, because pipeline agents may not rewrite history. Four optional Suggestions follow.

### Summary

The implementation follows the plan class for class. Its decisions are recorded as D-57 to D-61, and none of those numbers clash with §5.

- **Live smoke (AC 1–8, D-58):**
  - `LiveSmokeGuard` refuses before any client exists: production first, from the app or `.env`, then CI, then a missing key. Its messages name variables only, and the key is a `SensitiveParameter`.
  - `LiveSmokeGuardTest` covers every refusal under `Http::fake()` with `assertNothingSent()`. It also proves that `phpunit.xml` excludes `live` and includes nothing, and that the smoke carries the group at class level.
  - The smoke uses `live-smoke-` + a UUID as the key, which can never be a ULID. It pins the prompt at 51 code points and 81 UTF-16 units. The bounds and the max cost come from `EstimateGenerationCostAction`. Creates retry a `503` or `429` up to 3 times with the same key. Polling runs at most once per 5 s, for at most 3 min.
  - The pricing diff compares key-sorted copies with `assertSame`, with the percent mapped back to the live fraction.
- **Reconciler health (AC 18, D-59):** the command records the heartbeat only after the action returns. A failing row still counts as a run, and a throwing action records nothing; both cases are tested. The endpoint sits outside every middleware group. It returns exactly `status` and `last_succeeded_at`, with `no-store` and no cookie, and the 300/301 s boundary is tested.
- **Registration (AC 20, D-57):**
  - The limit is checked before validation and hit only after `User::create()`. It is read at call time because Fortify binds the creator as a singleton.
  - The copy rounds up to whole minutes, with "minute" for 1. `X-Forwarded-For` is ignored.
  - The env name is cleared in both places of `phpunit.xml`, and its 1,000 default is shared by `.env.example` and the compose anchor, so D-48 holds.
- **Expired session (AC 21, D-60):**
  - A 419 that is not a plain JSON call becomes a 303. It goes to the Referer only when that is on our host, else to Sign in. A guest's unsafe request to an `auth` route gains the same flash. `ExpiredSessionTest` checks CSRF for real through a never-skipping subclass. The E2E row covers the modern-browser guest path.
  - The Referer check uses `parse_url` host equality, so `https://host@evil/` and other hosts never become a target.
- **Hardening:**
  - `confirmPasswordsUsing` aborts 404, and `FortifyRoutesTest` proves nothing is confirmed (D-61).
  - `StorableTextRule` now guards the name, with `:Attribute` in the message.
  - `ProductionErrorPagesTest` registers its crash route inside the test and resets the trusted hosts in `tearDown()`.
  - The AC 15 assertions add no production code.
- **Operations:**
  - `.env.production.example` gains exactly the three Pixelforge names, with blank secrets.
  - The unused production Docker stage is removed, and its commit body explains why.
  - `app:check-generation-money` is read-only and prints keywords, never prompt text. Its action recomputes the estimate, the provider cost from the row's own rates, the +25 % charge and the exact ledger sequence.
  - The production smoke gains the health and no-redirect rows.
  - The README covers every AC 24 and AC 25 bullet. Its secret commands go over stdin, never argv, and it warns against `config:show pixelforge`.

Security: no secret value is committed, logged or printed. The command prints `[keyword]` tokens only. The only redirect target derived from input is checked against our host. The health endpoint exposes one instant.

The constant-field check: `/health/reconciler` returns `status` and `last_succeeded_at`. Neither is constant under a scope.

### Commit hygiene

- Each task commit carries its code with its tests, and every new env name is cleared in `phpunit.xml` in the commit that first reads it. The prompt log (`58c95a9`), the plan (`96c264e`), the decisions (`7e1dafc`) and the bookkeeping (`bcc6d81`) each have their own commit.
- All subjects start with `[PE-015]`, are imperative and are at most 72 characters. All 25 messages end with the trailer.
- The cut point after task 13 (`c03f923`) is clean.
- The exception is task 6, which is split into a commit that does not pass (W3).

### Warnings

- [x] **W1. `make live-smoke` drops the host's `CI` variable, so the CI refusal (AC 1) never fires through the only supported entry point.**
  - `Makefile:98` runs `docker exec prompt-edit-app php artisan test --compact --group=live`. `docker exec` passes no host environment into the container. `LiveSmokeGuard::fromRuntime()` (`tests/Feature/Pixelforge/Live/LiveSmokeGuard.php:44`) reads `getenv('CI')` inside the container, and the `app` service never sets `CI` (only `playwright` does, `docker-compose.yml:169`).
  - Verified: `CI=true docker exec prompt-edit-app sh -c 'echo ${CI-unset}'` prints `unset`. With `-e CI` it prints `true`, and without a host `CI` it stays `unset`.
  - Why it matters: AC 1 requires the smoke to refuse "when the `CI` environment variable is set", and the story says "it runs only through a dedicated Makefile target". As written, `CI=true make live-smoke` on a machine with a dev key in `.env` spends against the real API. `LiveSmokeGuardTest` proves the guard reads the process variable, but not that the target hands it over. The README (reviewer guide, Make Targets) and D-58 both state that the smoke refuses in CI.
  - Fix: forward the variable with `@docker exec -e CI prompt-edit-app php artisan test --compact --group=live`. A bare `-e CI` copies it only when the host sets it. Add a `LiveSmokeGuardTest` row that reads the `live-smoke` recipe from `Makefile` and asserts it forwards `CI` (`-e CI`), so the guard and its entry point stay tied together.
- [x] **W2. `CheckGenerationMoneyAction::holdProblems()` uses `elseif`, against the "no `else`" rule.**
  - `app/Domain/Billing/Actions/CheckGenerationMoneyAction.php:82`: `} elseif ($holds[0][1] !== -$holdAmount) {`. This is the only `elseif` under `app/`, and the guidelines say "No `else`: prefer early returns and guard clauses".
  - Fix: return early, for example `if (count($holds) !== 1) { return [...$problems, sprintf('%d holds, expected 1', count($holds))]; }`, followed by a plain `if` for the amount. `CheckGenerationMoneyActionTest` already covers both branches (the tampered hold row), so its existing rows prove the refactor.
- [ ] **W3. Commit `b6fa7a7` does not pass on its own (needs the user, not an Engineer task).**
  - `b6fa7a7 [PE-015] Move StorableTextRule to the Shared domain` only renames the file. At that commit, `app/Domain/Shared/Rules/StorableTextRule.php` still declares `namespace App\Domain\Generation\Rules`. `CreateGenerationRequest` still imports `App\Domain\Generation\Rules\StorableTextRule`, which PSR-4 resolves to a file that no longer exists, so every Generate request and its tests fail with "class not found". The next commit, `3976ce2`, fixes the namespace and the import, and its body admits the split.
  - Why it matters: the project rule is that every commit is self-contained and passes the suite, and the user reviews commit by commit. Plan task 6 was one commit.
  - Fix: squash `3976ce2` into `b6fa7a7`, keeping `b6fa7a7`'s subject, before the PR is opened (`git rebase -i origin/main` with `fixup`). Pipeline agents may not rebase or amend, so **the Engineer must not attempt this**. The orchestrator raises it with the user. Once the user has squashed the commits or accepted them as they are, the next review round ticks this as acknowledged.

### Suggestions

- **S1. The AC 7 rates check is key-order sensitive.** `tests/Feature/Pixelforge/Live/LivePixelforgeSmokeTest.php:204` runs `assertSame($this->pinnedRates(), $snapshot->rates)`. If Pixelforge reorders the `rates` object, the check fails even though nothing drifted. The AC 3 pricing diff avoids this with `sortedByKey()`. Use the same helper here: `assertSame(self::sortedByKey($this->pinnedRates()), self::sortedByKey($snapshot->rates))`.
- **S2. The default limit of 3 is written twice.** `app/Actions/Fortify/CreateNewUser.php:82` falls back to a literal `3`, and `config/auth.php` already defaults to 3 through `filter_var`. Name it once, for example as a `DEFAULT_REGISTRATIONS_PER_HOUR` constant on `CreateNewUser` that the config references, or cast the config value and drop the fallback.
- **S3. A guest's Sign out shows "Your session expired. Please try again."** `app/Http/Responses/RedirectExpiredSession.php:40` flashes the notice for any unsafe guest request that `auth` redirects, including `POST /logout` from a tab whose session has expired. The result is harmless but reads oddly ("try again" to sign out). Consider skipping the flash when `$request->routeIs('logout')`, with an `ExpiredSessionTest` row.
- **S4. The first `make smoke-production` after this deploy can read `stale`.** The heartbeat key does not exist on production until the first scheduled run after the deploy, and the new smoke row (`tests/smoke/production.spec.ts:22`) expects 200 `ok`. In README step 3 ("Deploy"), consider saying to wait a minute after `dep deploy`, or until `/health/reconciler` is `ok`, before running the smoke.
