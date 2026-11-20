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

## Round 2

**Verdict: REQUEST CHANGES**

Scope reviewed: the whole `[PE-015]` story diff (`git diff origin/main...HEAD`, `58c95a9..8cf3422`, 29 commits), with a close look at the round 1 fix commits `11f8f96`, `eee26de` and `eb36a1c` and the bookkeeping commit `8cf3422`.

Checks run on HEAD (`8cf3422`):
- `make check` exits 0. Pint (292 files) and Larastan are clean. PHPUnit runs 1,729 tests (37,027 assertions), all passing. ESLint, Prettier and vue-tsc are clean. Vitest runs 503 tests in 33 files, all passing.
- `CI=true make live-smoke` now refuses. Both non-skipped live tests fail with "The live smoke never runs in CI (the CI variable is set). Run `make live-smoke` locally." and the other 4 are skipped, so no request reaches the real API. The new `test_the_make_target_forwards_ci_into_the_container` passes on its own.
- Larastan run on `tests/Feature/Pixelforge/Live/LiveSmokeGuardTest.php` as of `11f8f96` (copied into the container and analysed with the project's `phpstan.neon`) reports 1 error at line 179: "Offset 'recipe' might not exist" (`offsetAccess.notFound`). See W4.
- `make e2e` was not re-run. Nothing since round 1 touches the UI, the routes or the frontend, and round 1's run (44 passed, 2 skipped) still stands.

### Round 1 follow-up

- **W1, CI forwarding (`11f8f96` + `eee26de`):** `Makefile:99` now runs `docker exec -e CI prompt-edit-app …`. A bare `-e CI` copies the variable only when the host sets it, so a local run without `CI` behaves as before. The Makefile comment says why. `LiveSmokeGuardTest::test_the_make_target_forwards_ci_into_the_container` reads the `live-smoke` recipe and pins `-e CI` together with `--group=live`, so the guard and its entry point stay tied. With `$matches['recipe'] ?? ''`, a missing or renamed target fails the pattern assertion instead of erroring. Fixed.
- **W2, `elseif` (`eb36a1c`):** `CheckGenerationMoneyAction::holdProblems()` (`app/Domain/Billing/Actions/CheckGenerationMoneyAction.php:80-86`) returns early on a wrong hold count and then checks the amount with a plain `if`. The behaviour is unchanged. The estimate-max problem is still collected first, and the existing `CheckGenerationMoneyActionTest` rows (including the tampered hold) still pass. `app/` has no `elseif` left. The one remaining `else` (`ReconcileGenerationsAction.php:146`) came from PE-014 and is outside this diff. Fixed.
- **W3, `b6fa7a7` does not pass on its own:** unchanged. `b6fa7a7` and `3976ce2` are still separate, and neither the prompt log nor the story records a decision by the user. It stays open (below).
- **Suggestions S1–S4:** not taken up. They are optional and do not block.

### Commit hygiene

- Each W fix has its own commit with its test, and its body gives the reason (`11f8f96`, `eee26de`). The bookkeeping `8cf3422` holds only `review.md`. Every subject is `[PE-015] Imperative …`, at most 72 characters, with no trailing period, and every commit carries the trailer.
- The exception is that `11f8f96` fails `make stan` on its own and `eee26de` is its fix-up (W4). This is the same shape as W3.

### Warnings

- [ ] **W3 (carried over). Commit `b6fa7a7` does not pass on its own (needs the user, not an Engineer task).** See round 1. The fix is to squash `3976ce2` into `b6fa7a7`, keeping `b6fa7a7`'s subject. Pipeline agents may not rebase or amend, so **the Engineer must not attempt this**. The orchestrator raises it with the user. Once the user has squashed the commits or accepted them as they are, the next round ticks this as acknowledged.
- [ ] **W4. Commit `11f8f96` fails Larastan on its own (needs the user, not an Engineer task).**
  - At `11f8f96 [PE-015] Forward CI into the container for make live-smoke`, `tests/Feature/Pixelforge/Live/LiveSmokeGuardTest.php:179` reads `$matches['recipe']` straight after `preg_match`. Larastan reports `Offset 'recipe' might not exist on array{}|array{…}` (verified above), so `make stan`, and with it `make check`, fails at that commit. The next commit, `eee26de [PE-015] Read the live-smoke recipe without an unchecked offset`, only fixes that line, and its body says so.
  - Why it matters: the project rule is that every commit is self-contained and passes its checks (`.agent-workflow/CLAUDE.md` § Git hygiene), and the user reviews commit by commit. `eee26de` reads as a separate change but is a fix-up of the previous commit.
  - Fix: squash `eee26de` into `11f8f96`, keeping `11f8f96`'s subject and body. This can be done in the same `git rebase -i origin/main` as W3 (`fixup` on `3976ce2` and on `eee26de`). Pipeline agents may not rebase or amend, so **the Engineer must not attempt this**. The orchestrator raises it with the user, together with W3. Once the user has squashed the commits or accepted them as they are, the next round ticks this as acknowledged.

No Critical findings. No code or test change is needed from the Engineer. The two open Warnings are history decisions that only the user can make. When both are resolved, the story can be approved without another code round.

## Round 3

**Verdict: REQUEST CHANGES**

Scope reviewed: the whole `[PE-015]` story diff (`git diff origin/main...HEAD`, `58c95a9..0fac805`, 31 commits, 53 files). Nothing has been committed since round 2 except its bookkeeping (`0fac805`, `review.md` only). I re-read every production file again: `RedirectExpiredSession`, `CreateNewUser`, `ReconcilerHeartbeat`, `ShowReconcilerHealthController`, `routes/health.php`, `bootstrap/app.php`, `FortifyServiceProvider`, `ReconcileGenerationsCommand`, `CheckGenerationMoneyAction`, `CheckGenerationMoneyCommand`, `config/auth.php`, `config/pixelforge.php`, `phpunit.xml` and the `Makefile` target. No new finding.

Checks run on HEAD (`0fac805`):
- `make check` exits 0. Pint (292 files) and Larastan are clean. PHPUnit runs 1,729 tests (37,027 assertions), all passing. ESLint, Prettier and vue-tsc are clean. Vitest runs 503 tests in 33 files, all passing.
- `make e2e` and `make live-smoke` were not re-run. No code has changed since round 1's runs (E2E 44 passed, 2 skipped; live smoke 6 passed), so those results still stand. Re-running the live smoke would also spend against the shared Pixelforge limits for no gain.

### Commit hygiene

- All 31 subjects start with `[PE-015]`, are imperative, have no trailing period and are at most 72 characters. All 31 messages end with the trailer. The bookkeeping commits (`bcc6d81`, `a17254a`, `8cf3422`, `0fac805`) hold no code.
- The history still has the two fix-up pairs from earlier rounds. `b6fa7a7` still declares `namespace App\Domain\Generation\Rules` in `app/Domain/Shared/Rules/StorableTextRule.php` (W3). `eee26de` still only fixes the Larastan offset error that `11f8f96` introduced (W4).
- `.agent-workflow/prompts.md` records no decision by the user on either pair. "Continue" is an operational message and is not read as accepting the history.

### Warnings

- [ ] **W3 (carried over). Commit `b6fa7a7` does not pass on its own (needs the user, not an Engineer task).** See round 1. Fix: squash `3976ce2` into `b6fa7a7`, keeping `b6fa7a7`'s subject. **The Engineer must not attempt this**, because pipeline agents may not rebase or amend. The orchestrator raises it with the user.
- [ ] **W4 (carried over). Commit `11f8f96` fails Larastan on its own (needs the user, not an Engineer task).** See round 2. Fix: squash `eee26de` into `11f8f96`, keeping `11f8f96`'s subject and body. Do it in the same `git rebase -i origin/main` as W3. **The Engineer must not attempt this.** The orchestrator raises it with the user, together with W3.

There are no Critical findings, and the code needs no change. Another Engineer or review round would not change anything, so the pipeline should stop here and ask the user one question: squash the two fix-up pairs (W3, W4), or accept the history as it is. Either answer closes both Warnings:
- If the user squashes, the next round re-checks the rewritten commits (`make check` at each of the two squashed commits) and approves.
- If the user accepts the history, the answer is logged in `prompts.md`, and the next round ticks W3 and W4 as acknowledged and approves.
